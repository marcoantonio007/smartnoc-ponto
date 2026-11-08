<?php
// api_ponto.php — API do ponto eletrônico (ponto.smartnoc.com.br).
// Login com as mesmas credenciais da escala (tabela usuarios). Colaborador = usuário vinculado a um funcionário. Gestor = perfil admin/mestre.
$seguro = getenv('PONTO_COOKIE_SECURE') === '1';
session_name('PONTOSESS');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $seguro, 'httponly' => true, 'samesite' => 'Lax']);
session_start();
require_once '/var/www/lib/conexao.php';
require_once '/var/www/lib/ponto_lib.php';
require_once '/var/www/lib/ponto_email.php';
require_once '/var/www/lib/ponto_fiscal.php'; // PORTARIA_671_V1: AFD/AEJ e LGPD
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function resp($arr, $code = 200) { http_response_code($code); echo json_encode($arr, JSON_UNESCAPED_UNICODE); exit; }
function erro($msg, $code = 200) { resp(['sucesso' => false, 'erro' => $msg], $code); }
if (!$pdo) erro('Sem conexão com o banco de dados.', 500);
try { ptGarantirTabelas($pdo); ptGarantirAtestados($pdo); ptGarantirHoraExtra($pdo); ptEmailGarantir($pdo); ptFiscalGarantir($pdo); } catch (Exception $e) { erro('Falha ao preparar o banco: ' . $e->getMessage(), 500); }
try { ptLgpdExpurgo($pdo); } catch (Exception $e) { error_log('ponto: expurgo LGPD falhou: ' . $e->getMessage()); }
register_shutdown_function('ptSyncFimDaRequisicao', $pdo); // hora extra -> Escala mensal
register_shutdown_function('ptEmailFimDaRequisicao', $pdo); // relatório de fim de folha por e-mail (gatilho do site; o cron também pode enviar)

// Atestados médicos (dia inteiro ou consulta) enviados pelo colaborador, com o anexo, e decididos pelo gestor
function ptGarantirAtestados($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_atestados (
        id SERIAL PRIMARY KEY, nome VARCHAR(150) NOT NULL, tipo VARCHAR(10) NOT NULL, data_ini DATE NOT NULL, data_fim DATE NOT NULL,
        hora_ini TIME, hora_fim TIME, observacao TEXT, arquivo_nome VARCHAR(200), arquivo_tipo VARCHAR(40), arquivo TEXT,
        status VARCHAR(10) NOT NULL DEFAULT 'pendente', decidido_por VARCHAR(100), decidido_em TIMESTAMP, motivo_decisao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pa_nome ON ponto_atestados (nome, data_ini)");
    $pdo->exec("ALTER TABLE ponto_atestados ADD COLUMN IF NOT EXISTS motivo VARCHAR(80)"); // solicitação de abono: motivo escolhido pelo colaborador
    // batida feita sem internet: guardada no aparelho e enviada depois (uid evita duplicidade se o envio se repetir)
    $pdo->exec("ALTER TABLE ponto_batidas ADD COLUMN IF NOT EXISTS uid_offline VARCHAR(64), ADD COLUMN IF NOT EXISTS offline BOOLEAN NOT NULL DEFAULT FALSE, ADD COLUMN IF NOT EXISTS sincronizado_em TIMESTAMP");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_pb_uid ON ponto_batidas (nome, uid_offline) WHERE uid_offline IS NOT NULL");
}
const PT_OFFLINE_MAX_DIAS = 3; // batida offline só é aceita se enviada em até 3 dias; depois disso, batida manual justificada
function ptRotuloAtestado($tipo, $motivo = null) { return $motivo ?: ($tipo === 'dia' ? 'Atestado médico (dia)' : 'Atestado médico (consulta)'); }
const PT_MOTIVOS_ABONO = ['Licença Casamento', 'Feriado', 'Atestado Médico', 'Atestado de Óbito', 'Declaração Médica (Comparecimento)', 'Integração Treinamento Ponto',
    'Folga TRE', 'Licença Alistamento Militar', 'Exame Vestibular', 'Licença Paternidade', 'Espro/ Ciee (Aprendiz)', 'Abonado pelo Gestor', 'Folga de Campo', 'Troca de escala', 'Day Off Aniversário'];

// ---- "Esqueci minha senha": código de 6 dígitos por e-mail (válido 15 min, 5 tentativas) + nova senha com regras ----
const PT_SENHA_VALIDADE_MIN = 15;
const PT_SENHA_TENTATIVAS = 5;
function ptSenhaTabela($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_reset_senha (
        id SERIAL PRIMARY KEY, usuario VARCHAR(100) NOT NULL, salt VARCHAR(32), codigo_hash VARCHAR(64), expira_em TIMESTAMP,
        tentativas INT NOT NULL DEFAULT 0, usado BOOLEAN NOT NULL DEFAULT TRUE, ip VARCHAR(45), criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_prs_usuario ON ponto_reset_senha (usuario, criado_em)");
}
function ptSenhaFalhas($s) { // regras da nova senha (a tela confere as mesmas enquanto a pessoa digita)
    if (!mb_check_encoding($s, 'UTF-8')) return ['caracteres inválidos'];
    $f = [];
    if (mb_strlen($s) < 8) $f[] = 'mínimo de 8 caracteres';
    if (mb_strlen($s) > 64) $f[] = 'no máximo 64 caracteres';
    if (!preg_match('/\p{L}/u', $s)) $f[] = 'pelo menos 1 letra';
    if (!preg_match('/\p{Nd}/u', $s)) $f[] = 'pelo menos 1 número';
    if (!preg_match('/[^\p{L}\p{N}\s]/u', $s)) $f[] = 'pelo menos 1 caractere especial';
    if (preg_match('/\s/u', $s)) $f[] = 'sem espaços';
    return $f;
}
function ptSenhaHash($salt, $usuario, $cod) { return hash('sha256', $salt . '|' . $usuario . '|' . $cod); }
function ptSenhaPrimeiroNome($nome) { return ucfirst(mb_strtolower(explode(' ', trim((string)$nome))[0])); }
function ptSenhaEmailCodigo($nomeCompleto, $cod) {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); $prim = ptSenhaPrimeiroNome($nomeCompleto);
    $html = '<p>Olá, <b>' . $h($prim) . '</b>.</p><p>Recebemos um pedido para redefinir a senha do seu acesso ao ponto. Use o código abaixo (válido por ' . PT_SENHA_VALIDADE_MIN . ' minutos):</p>' .
        '<p style="margin:18px 0;text-align:center"><span style="display:inline-block;background:#eff6ff;border:1px solid #bfdbfe;padding:14px 26px;font-size:32px;font-weight:bold;letter-spacing:8px;color:#1d4ed8;font-family:Consolas,Menlo,monospace">' . $h($cod) . '</span></p>' .
        '<p style="font-size:13px;color:#4b5563">Digite o código na tela de recuperação de senha e escolha a nova senha. Se você <b>não</b> fez esse pedido, ignore este e-mail: a sua senha continua a mesma. Nunca compartilhe este código com ninguém.</p>' .
        '<p style="font-size:11px;color:#6b7280">Pedido feito em ' . date('d/m/Y H:i') . '.</p>';
    return ['Seu código para redefinir a senha — SmartNOC Ponto', ptEmailLayout('Código para redefinir sua senha', $html),
            "Olá, $prim.\n\nSeu código para redefinir a senha do ponto: $cod\nVálido por " . PT_SENHA_VALIDADE_MIN . " minutos. Se você não fez esse pedido, ignore este e-mail.\n"];
}
function ptSenhaEmailAlterada($nomeCompleto) {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); $prim = ptSenhaPrimeiroNome($nomeCompleto);
    $html = '<p>Olá, <b>' . $h($prim) . '</b>.</p><p>A senha do seu acesso ao ponto foi alterada em <b>' . date('d/m/Y H:i') . '</b>.</p>' .
        '<p style="font-size:13px;color:#b91c1c"><b>Não foi você?</b> Fale com o seu supervisor imediatamente.</p>';
    return ['Sua senha foi alterada — SmartNOC Ponto', ptEmailLayout('Senha alterada', $html), "Olá, $prim.\n\nA senha do seu acesso ao ponto foi alterada em " . date('d/m/Y H:i') . ". Se não foi você, fale com o seu supervisor imediatamente.\n"];
}

$action = $_GET['action'] ?? '';
$in = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) erro('Requisição inválida.', 400);
    $in = json_decode(file_get_contents('php://input'), true);
    if (!is_array($in)) erro('Requisição inválida.', 400);
}

function ehGestor() { $p = $_SESSION['perfil'] ?? ''; return isset($_SESSION['usuario']) && ($p === 'admin' || $p === 'mestre'); }
function exigeGestor() { if (!ehGestor()) erro('Acesso restrito a gestores.', 403); }
function exigeLogin() { if (empty($_SESSION['usuario'])) erro('Sessão expirada. Entre novamente.', 401); }
function exigeColab($pdo) {
    exigeLogin();
    $alvo = trim((string)($_GET['alvo'] ?? ''));
    if ($alvo !== '') { // Apuração de Batida: o gestor trabalha na folha do colaborador escolhido
        exigeGestor();
        $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $alvo]);
        if (!$st->fetchColumn()) erro('Colaborador não encontrado.');
        global $action; // regra "gestor pode incluir/remover batidas dos subordinados" (aba Regra de horário); a folha do próprio gestor não é afetada
        if (in_array($action, ['ponto_manual', 'ponto_remover', 'ponto_cancelar', 'ponto_extra_manual'], true) && $alvo !== ($_SESSION['ponto_nome'] ?? null) && !ptGestorEdita($pdo))
            erro('A inclusão e a remoção de batidas pelo gestor estão desabilitadas (Administração → Regra de horário).');
        return $alvo;
    }
    if (empty($_SESSION['ponto_nome'])) erro('Seu usuário não está vinculado a um colaborador cadastrado na escala. Fale com o gestor.', 403);
    $c = colab($pdo, $_SESSION['ponto_nome']);
    if ($c && !$c['ativo']) erro('Seu acesso ao ponto está desativado. Fale com o gestor.', 403);
    return $_SESSION['ponto_nome'];
}
// Quem está agindo: o próprio colaborador ou o gestor que mexe na folha de outro (fica registrado na auditoria)
function atorDe($nome) { return (!empty($_SESSION['ponto_nome']) && $_SESSION['ponto_nome'] === $nome) ? $nome : ($_SESSION['usuario'] ?? $nome); }
function porConta($nome) { return atorDe($nome) === $nome ? '' : " [folha de $nome, lançado pelo gestor]"; }
function vincularFuncionario($pdo, $nomeCompleto) { // mesma regra da escala: nome completo do login = funcionarios.nome ou nome_completo
    $nomeCompleto = trim((string)$nomeCompleto);
    if ($nomeCompleto === '') return null;
    $st = $pdo->prepare("SELECT nome FROM funcionarios WHERE upper(nome_completo) = upper(:a) OR upper(nome) = upper(:b) ORDER BY (nome_completo = :c) DESC, nome LIMIT 1");
    $st->execute(['a' => $nomeCompleto, 'b' => $nomeCompleto, 'c' => $nomeCompleto]);
    $n = $st->fetchColumn();
    return $n ?: null;
}
function sessaoInfo($pdo) {
    return ['usuario' => $_SESSION['usuario'], 'nome_login' => $_SESSION['nome'] ?? $_SESSION['usuario'], 'perfil' => $_SESSION['perfil'] ?? '',
            'gestor' => ehGestor(), 'vinculado' => !empty($_SESSION['ponto_nome'])];
}
function chaveFalha($u) { return 'u:' . mb_strtolower(mb_substr($u, 0, 60)); }
function loginBloqueado($pdo, $k) {
    $st = $pdo->prepare("SELECT 1 FROM ponto_login_falhas WHERE chave = :k AND bloqueado_ate > now()"); $st->execute(['k' => $k]);
    return (bool)$st->fetchColumn();
}
function loginFalhou($pdo, $k) {
    $pdo->prepare("INSERT INTO ponto_login_falhas (chave, tentativas, atualizado_em) VALUES (:k, 1, now())
        ON CONFLICT (chave) DO UPDATE SET
          bloqueado_ate = CASE WHEN ponto_login_falhas.tentativas + 1 >= 5 THEN now() + interval '10 minutes' ELSE ponto_login_falhas.bloqueado_ate END,
          tentativas = CASE WHEN ponto_login_falhas.tentativas + 1 >= 5 THEN 0 ELSE ponto_login_falhas.tentativas + 1 END,
          atualizado_em = now()")->execute(['k' => $k]);
}
function auditar($pdo, $usuario, $acao, $det) {
    try { $pdo->prepare("INSERT INTO trilha_auditoria (usuario, acao, detalhes) VALUES (:u, :a, :d)")->execute(['u' => $usuario, 'a' => $acao, 'd' => $det]); } catch (Exception $e) {}
}
function ipCliente() {
    $x = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($x !== '') { $p = trim(explode(',', $x)[0]); if (filter_var($p, FILTER_VALIDATE_IP)) return $p; }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}
// Histórico de auditoria (somente inserção): quem fez o quê, quando, de onde.
function evento($pdo, $batidaId, $nome, $data, $evento, $tipo, $momento, $usuario, $det) {
    try {
        $pdo->prepare("INSERT INTO ponto_eventos (batida_id, nome, data_ref, evento, tipo, momento, usuario, detalhes, ip, agente)
                       VALUES (:b, :n, :d, :e, :t, :m, :u, :det, :ip, :ag)")
            ->execute(['b' => $batidaId, 'n' => $nome, 'd' => $data, 'e' => $evento, 't' => $tipo, 'm' => $momento, 'u' => $usuario,
                       'det' => $det, 'ip' => ipCliente(), 'ag' => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)]);
    } catch (Exception $e) {}
    ptAgendarSyncHE($nome, $data);
}
function colab($pdo, $nome) {
    $st = $pdo->prepare("SELECT * FROM ponto_colaboradores WHERE nome = :n"); $st->execute(['n' => $nome]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
function falhar($pdo, $nome) { // contador de falhas (PIN ou rosto): 5 falhas => bloqueio de 10 min
    $pdo->prepare("UPDATE ponto_colaboradores SET
                   bloqueado_ate = CASE WHEN tentativas + 1 >= 5 THEN now() + interval '10 minutes' ELSE bloqueado_ate END,
                   tentativas = CASE WHEN tentativas + 1 >= 5 THEN 0 ELSE tentativas + 1 END WHERE nome = :n")->execute(['n' => $nome]);
}
function bloqueado($c) { return !empty($c['bloqueado_ate']) && strtotime($c['bloqueado_ate']) > time(); }

try {
switch ($action) {

// =================== LOGIN / SESSÃO ===================
case 'ponto_login': {
    $u = trim((string)($in['usuario'] ?? '')); $senha = trim((string)($in['senha'] ?? ''));
    if ($u === '' || $senha === '') erro('Informe usuário e senha.');
    $k = chaveFalha($u);
    if (loginBloqueado($pdo, $k)) erro('Muitas tentativas incorretas. Tente novamente em alguns minutos.');
    $st = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = :u"); $st->execute(['u' => $u]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $ok = false;
    if ($row) {
        $guard = (string)$row['senha'];
        $ok = hash_equals($guard, $senha) || (preg_match('/^\$2[aby]\$/', $guard) && password_verify($senha, $guard));
    }
    if (!$ok) { loginFalhou($pdo, $k); erro('Credenciais inválidas.'); }
    $pdo->prepare("DELETE FROM ponto_login_falhas WHERE chave = :k")->execute(['k' => $k]);
    session_regenerate_id(true);
    $_SESSION = [];
    $_SESSION['usuario'] = $row['usuario']; $_SESSION['perfil'] = $row['perfil']; $_SESSION['nome'] = $row['nome_completo'];
    $f = vincularFuncionario($pdo, $row['nome_completo']);
    if ($f) { $_SESSION['ponto_nome'] = $f; $pdo->prepare("INSERT INTO ponto_colaboradores (nome) VALUES (:n) ON CONFLICT (nome) DO NOTHING")->execute(['n' => $f]); }
    resp(['sucesso' => true]);
}
case 'ponto_senha_solicitar': { // 1º passo: envia o código para o e-mail cadastrado do usuário (a resposta é a mesma exista ou não o usuário)
    $u = trim((string)($in['usuario'] ?? ''));
    if ($u === '' || mb_strlen($u) > 100) erro('Informe o usuário.');
    $cfg = smtpConfigLer($pdo);
    if (!smtpConfigurado($cfg)) erro('O envio de e-mail ainda não está configurado. Fale com o gestor.');
    ptSenhaTabela($pdo); $ip = substr((string)ipCliente(), 0, 45);
    $q = $pdo->prepare("SELECT count(*) FROM ponto_reset_senha WHERE ip = :ip AND criado_em > now() - interval '1 hour'"); $q->execute(['ip' => $ip]);
    if ((int)$q->fetchColumn() >= 10) erro('Muitas solicitações deste aparelho. Tente novamente mais tarde.');
    $q = $pdo->prepare("SELECT count(*) AS n, COALESCE(bool_or(criado_em > now() - interval '60 seconds'), false) AS recente FROM ponto_reset_senha WHERE lower(usuario) = lower(:u) AND criado_em > now() - interval '15 minutes'");
    $q->execute(['u' => $u]); $lim = $q->fetch(PDO::FETCH_ASSOC);
    if ((int)$lim['n'] >= 3) erro('Foram pedidos códigos demais. Aguarde alguns minutos e tente de novo.');
    if ($lim['recente']) erro('Aguarde 1 minuto para pedir um novo código.');
    $st = $pdo->prepare("INSERT INTO ponto_reset_senha (usuario, ip) VALUES (:u, :ip) RETURNING id"); $st->execute(['u' => $u, 'ip' => $ip]); $idReset = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT usuario, nome_completo FROM usuarios WHERE usuario = :u"); $st->execute(['u' => $u]);
    $row = $st->fetch(PDO::FETCH_ASSOC); $email = null;
    if ($row) { $f = vincularFuncionario($pdo, $row['nome_completo']); $email = $f ? ptEmailDoColab($pdo, $f) : null; }
    if ($row && $email) {
        $cod = sprintf('%06d', random_int(0, 999999)); $salt = bin2hex(random_bytes(8));
        [$assunto, $html, $txt] = ptSenhaEmailCodigo($row['nome_completo'], $cod);
        $r = smtpEnviar($cfg, $email, $assunto, $html, $txt);
        if (!$r['ok']) { error_log('ponto: e-mail de recuperação de senha: ' . $r['erro']); erro('Não foi possível enviar o e-mail agora. Tente novamente em instantes ou fale com o gestor.'); }
        $pdo->prepare("UPDATE ponto_reset_senha SET usado = TRUE WHERE usuario = :u AND NOT usado")->execute(['u' => $u]); // invalida códigos anteriores
        $pdo->prepare("UPDATE ponto_reset_senha SET salt = :s, codigo_hash = :h, expira_em = now() + interval '" . PT_SENHA_VALIDADE_MIN . " minutes', usado = FALSE WHERE id = :id")
            ->execute(['s' => $salt, 'h' => ptSenhaHash($salt, $u, $cod), 'id' => $idReset]);
    }
    auditar($pdo, $u, 'SENHA', 'Pediu código para redefinir a senha' . ($row && $email ? '' : ' (nada enviado: usuário inexistente ou sem e-mail cadastrado)') . ' · IP ' . $ip);
    resp(['sucesso' => true, 'mensagem' => 'Se este usuário tiver um e-mail cadastrado, enviamos um código de 6 dígitos (válido por ' . PT_SENHA_VALIDADE_MIN . ' minutos). Confira também a caixa de spam.']);
}
case 'ponto_senha_redefinir': { // 2º passo: confere o código e grava a nova senha
    $u = trim((string)($in['usuario'] ?? '')); $cod = preg_replace('/\D/', '', (string)($in['codigo'] ?? '')); $nova = (string)($in['senha'] ?? '');
    if ($u === '' || mb_strlen($u) > 100 || strlen($cod) !== 6) erro('Informe o usuário e o código de 6 dígitos.');
    if ($falhas = ptSenhaFalhas($nova)) erro('A senha precisa ter: ' . implode('; ', $falhas) . '.');
    ptSenhaTabela($pdo);
    $st = $pdo->prepare("SELECT * FROM ponto_reset_senha WHERE usuario = :u AND NOT usado AND codigo_hash IS NOT NULL AND expira_em > now() ORDER BY id DESC LIMIT 1");
    $st->execute(['u' => $u]); $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) erro('Código inválido ou expirado. Solicite um novo código.');
    if (!hash_equals($r['codigo_hash'], ptSenhaHash($r['salt'], $u, $cod))) {
        $pdo->prepare("UPDATE ponto_reset_senha SET tentativas = tentativas + 1, usado = (tentativas + 1 >= " . PT_SENHA_TENTATIVAS . ") WHERE id = :id")->execute(['id' => $r['id']]);
        erro((int)$r['tentativas'] + 1 >= PT_SENHA_TENTATIVAS ? 'Código incorreto. Foram muitas tentativas: solicite um novo código.' : 'Código incorreto. Confira o e-mail recebido.');
    }
    $st = $pdo->prepare("SELECT usuario, senha, nome_completo FROM usuarios WHERE usuario = :u"); $st->execute(['u' => $u]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) erro('Código inválido ou expirado. Solicite um novo código.');
    if (hash_equals((string)$row['senha'], $nova) || (preg_match('/^\$2[aby]\$/', (string)$row['senha']) && password_verify($nova, (string)$row['senha']))) erro('A nova senha deve ser diferente da atual.');
    $uso = $pdo->prepare("UPDATE ponto_reset_senha SET usado = TRUE WHERE id = :id AND NOT usado"); $uso->execute(['id' => $r['id']]);
    if ($uso->rowCount() !== 1) erro('Código inválido ou expirado. Solicite um novo código.');
    // a Escala compara a senha em texto puro (index.php), então ela é gravada no mesmo formato que o cadastro de usuários da Escala usa
    $pdo->prepare("UPDATE usuarios SET senha = :s WHERE usuario = :u")->execute(['s' => $nova, 'u' => $u]);
    $pdo->prepare("UPDATE ponto_reset_senha SET usado = TRUE WHERE usuario = :u")->execute(['u' => $u]);
    $pdo->prepare("DELETE FROM ponto_login_falhas WHERE chave = :k")->execute(['k' => chaveFalha($u)]); // desbloqueia o login, se estava bloqueado por tentativas
    auditar($pdo, $u, 'SENHA', 'Redefiniu a própria senha pelo "Esqueci minha senha" · IP ' . ipCliente());
    $f = vincularFuncionario($pdo, $row['nome_completo']); $email = $f ? ptEmailDoColab($pdo, $f) : null;
    if ($email) { [$a, $h, $t] = ptSenhaEmailAlterada($row['nome_completo']); $x = smtpEnviar(smtpConfigLer($pdo), $email, $a, $h, $t); if (!$x['ok']) error_log('ponto: aviso de senha alterada: ' . $x['erro']); }
    resp(['sucesso' => true]);
}
case 'ponto_logout': { $_SESSION = []; session_destroy(); resp(['sucesso' => true]); }
case 'ponto_me': {
    exigeLogin();
    $info = sessaoInfo($pdo);
    if (empty($_SESSION['ponto_nome'])) resp(['sucesso' => true] + $info);
    $nome = $_SESSION['ponto_nome']; $c = colab($pdo, $nome);
    if (!$c || !$c['ativo']) resp(['sucesso' => true, 'desativado' => true, 'nome' => $nome] + $info);
    $regra = ptRegra($pdo, $nome); $hoje = date('Y-m-d');
    $alertas = [];
    for ($i = 0; $i <= PT_JANELA_DIAS; $i++) {
        $d = date('Y-m-d', strtotime("$hoje -$i days"));
        if (!empty($c['cadastrado_em']) && $d < substr($c['cadastrado_em'], 0, 10)) break; // só cobra a partir do cadastro do rosto
        $s = ptStatusDia($pdo, $regra, $nome, $d);
        if ($s['alerta'] || $s['pendentes']) $alertas[] = $s;
    }
    resp(['sucesso' => true, 'nome' => $nome, 'cadastrado' => !empty($c['descritor']),
          'agora' => date('Y-m-d H:i:s'), 'hoje' => ptStatusDia($pdo, $regra, $nome, $hoje),
          'alertas' => array_values(array_filter($alertas, function ($a) { return $a['alerta']; })),
          'rotulos' => PT_LABEL, 'tolerancia' => (int)$regra['tolerancia'], 'flex_min' => (int)($regra['flex_min'] ?? 0), 'almoco_auto' => !empty($regra['almoco_auto'])] + $info);
}
case 'ponto_historico': {
    $nome = exigeColab($pdo); $regra = ptRegra($pdo, $nome); $out = [];
    $hoje = date('Y-m-d'); [$ini, $fim] = ptFolhaPeriodo(ptFolhaRefDe($hoje)); // somente a folha vigente (15 a 14)
    for ($d = min($hoje, $fim); $d >= $ini; $d = date('Y-m-d', strtotime("$d -1 day"))) {
        $s = ptStatusDia($pdo, $regra, $nome, $d);
        if ($s['batidas'] || $s['alerta']) $out[] = $s;
    }
    resp(['sucesso' => true, 'dias' => $out, 'rotulos' => PT_LABEL, 'inicio' => $ini, 'fim' => $fim]);
}
case 'ponto_cadastrar_rosto': {
    $nome = exigeColab($pdo); $c = colab($pdo, $nome);
    if (!empty($c['descritor'])) erro('Rosto já cadastrado. Peça ao gestor para resetar o cadastro.');
    if (!ptDescritorValido($in['descritor'] ?? null)) erro('Leitura do rosto inválida. Tente novamente.');
    if (!ptFotoValida($in['foto'] ?? null)) erro('Foto inválida.');
    if (empty($in['consentimento'])) erro('É necessário aceitar o uso dos dados biométricos.');
    $pdo->prepare("UPDATE ponto_colaboradores SET descritor = :d, foto_cadastro = :f, cadastrado_em = now(), consentimento_em = now() WHERE nome = :n")
        ->execute(['d' => json_encode(array_map('floatval', $in['descritor'])), 'f' => $in['foto'], 'n' => $nome]);
    auditar($pdo, $nome, 'PONTO', 'Cadastrou o rosto para o ponto (com consentimento LGPD)');
    resp(['sucesso' => true]);
}
case 'ponto_bater': {
    $nome = exigeColab($pdo); $c = colab($pdo, $nome);
    $uid = (isset($in['uid']) && is_string($in['uid']) && preg_match('/^[A-Za-z0-9-]{8,64}$/', $in['uid'])) ? $in['uid'] : null;
    if ($uid) { // reenvio da mesma batida (resposta perdida ou fila offline): devolve a já registrada
        $q = $pdo->prepare("SELECT id, tipo, to_char(momento,'HH24:MI:SS') AS hora FROM ponto_batidas WHERE nome = :n AND uid_offline = :u");
        $q->execute(['n' => $nome, 'u' => $uid]); $ja = $q->fetch(PDO::FETCH_ASSOC);
        if ($ja) resp(['sucesso' => true, 'duplicado' => true, 'tipo' => $ja['tipo'], 'rotulo' => PT_LABEL[$ja['tipo']], 'hora' => $ja['hora'], 'id' => (int)$ja['id']]);
    }
    $off = !empty($in['offline']); $agora = time(); $ref = $agora;
    if ($off) { // horário registrado no aparelho (sem internet); aceita só até 2 min no futuro e até PT_OFFLINE_MAX_DIAS dias atrás
        $ts = (string)($in['ts'] ?? ''); $t = preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $ts) ? strtotime($ts) : false;
        if ($t === false) erro('Horário da batida offline inválido.');
        if ($t > $agora + 120) erro('Horário da batida offline está no futuro. Verifique a data e hora do aparelho.');
        if ($t < $agora - PT_OFFLINE_MAX_DIAS * 86400) erro('Batida offline com mais de ' . PT_OFFLINE_MAX_DIAS . ' dias sem envio. Lance manualmente com justificativa.');
        $ref = $t;
    }
    if (empty($c['descritor'])) erro('Cadastre seu rosto antes de bater o ponto.');
    if (bloqueado($c)) erro('Acesso bloqueado por tentativas incorretas. Tente novamente em alguns minutos.');
    if (!ptDescritorValido($in['descritor'] ?? null)) erro('Rosto não detectado. Posicione o rosto na câmera.');
    if (!ptFotoValida($in['foto'] ?? null)) erro('Foto inválida.');
    if (!ptCoordValida($in['lat'] ?? null, $in['lng'] ?? null, $in['precisao'] ?? null)) erro('Localização obrigatória: permita o acesso à localização do aparelho.');
    $dist = ptDistancia(json_decode($c['descritor'], true), $in['descritor']);
    if ($dist > PT_DIST_MAX) { falhar($pdo, $nome); erro('Rosto não reconhecido. Tente novamente com boa iluminação.'); }
    $pdo->prepare("UPDATE ponto_colaboradores SET tentativas = 0 WHERE nome = :n")->execute(['n' => $nome]);
    $hoje = date('Y-m-d', $ref);
    if (($ad = ptAdmissao($pdo, $nome)) && $hoje < $ad) erro('Sua data de admissão é ' . date('d/m/Y', strtotime($ad)) . '. Não é possível registrar ponto antes dela.');
    $pdo->beginTransaction();
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:n))")->execute(['n' => $nome]);
    $bat = ptBatidasDoDia($pdo, $nome, $hoje);
    $tipo = ptProximoTipo($bat);
    $regraB = ptRegra($pdo, $nome); $rB = $regraB['dias'][(string)date('w', $ref)] ?? null;
    $fdsB = ptDiaFds($bat, !$rB || ptDiaSemObrigacao($pdo, $nome, $hoje)); // dia sem expediente: pares de entrada e saída
    if ($fdsB) { $tipo = ptProximoTipoFds($bat); if (!$tipo) { $pdo->rollBack(); erro('As entradas e saídas de hoje já foram registradas (2 períodos).'); } }
    elseif (empty($in['extra']) && $tipo === 'SAIDA_ALMOCO' && !empty($regraB['almoco_auto'])) { // almoço automático: 6h ou mais depois da entrada é o fim do expediente
        foreach (ptAtivas($bat) as $bb) if ($bb['tipo'] === 'ENTRADA' && $ref - strtotime($bb['momento']) >= 6 * 3600) $tipo = 'SAIDA';
    }
    if (!$fdsB && !empty($in['extra'])) { // hora extra pelo rosto: só depois do fim de expediente (início da extra e depois o fim da extra)
        $tem = array_column(ptAtivas($bat), 'tipo');
        if ($tipo || !in_array('SAIDA', $tem, true)) { $pdo->rollBack(); erro('A hora extra começa depois do fim de expediente. Registre antes o "Fim de expediente".'); }
        $tipo = !in_array('EXTRA_ENTRADA', $tem, true) ? 'EXTRA_ENTRADA' : (!in_array('EXTRA_SAIDA', $tem, true) ? 'EXTRA_SAIDA' : null);
        if (!$tipo) { $pdo->rollBack(); erro('O início e o fim da hora extra de hoje já foram registrados.'); }
    }
    if (!$tipo) { $pdo->rollBack(); erro('As quatro batidas de hoje já foram registradas.'); }
    $mom = date('Y-m-d H:i:s', $ref);
    $ult = ptAtivas($bat); // evita duplo clique: mínimo de 1 minuto entre batidas
    if ($ult) { $maior = 0; foreach ($ult as $u) $maior = max($maior, strtotime($u['momento'])); if ($ref - $maior < 60 && $ref >= $maior) { $pdo->rollBack(); erro('Aguarde 1 minuto entre as batidas.'); } }
    if ($e = ptValidarOrdem($bat, $tipo, $mom)) { $pdo->rollBack(); erro($e . ' Corrija com uma batida manual justificada.'); }
    $ins = $pdo->prepare("INSERT INTO ponto_batidas (nome, data_ref, tipo, momento, origem, foto, lat, lng, precisao, distancia, status, uid_offline, offline, sincronizado_em)
                          VALUES (:n, :d, :t, :m, 'facial', :f, :la, :ln, :p, :di, 'valida', :u, :of, " . ($off ? 'CURRENT_TIMESTAMP' : 'NULL') . ") RETURNING id");
    $ins->execute(['n' => $nome, 'd' => $hoje, 't' => $tipo, 'm' => $mom, 'f' => $in['foto'], 'la' => $in['lat'], 'ln' => $in['lng'],
                   'p' => $in['precisao'] ?? null, 'di' => round($dist, 4), 'u' => $uid, 'of' => $off ? 'true' : 'false']);
    $novoId = (int)$ins->fetchColumn();
    $pdo->commit();
    evento($pdo, $novoId, $nome, $hoje, 'BATIDA_FACIAL', $tipo, $mom, $nome, ($off ? 'Reconhecimento facial OFFLINE (feito no aparelho às ' . date('H:i:s', $ref) . ', enviado às ' . date('H:i:s', $agora) . '; distância ' : 'Reconhecimento facial (distância ') . round($dist, 3) . '; local ' . round((float)$in['lat'], 5) . ', ' . round((float)$in['lng'], 5) . ')');
    if ($tipo === 'SAIDA') ptAlmocoAuto($pdo, $nome, $hoje);
    resp(['sucesso' => true, 'tipo' => $tipo, 'rotulo' => ($fdsB ? PT_LABEL_FDS[$tipo] : PT_LABEL[$tipo]), 'hora' => date('H:i:s', $ref), 'id' => $novoId, 'distancia' => round($dist, 3)]);
}
case 'ponto_manual': {
    $nome = exigeColab($pdo); $data = $in['data'] ?? ''; $tipo = $in['tipo'] ?? ''; $hora = $in['hora'] ?? ''; $just = trim((string)($in['justificativa'] ?? ''));
    if (!ptDataValida($data)) erro('Data inválida.');
    $hoje = date('Y-m-d');
    if ($data > $hoje) erro('Não é possível lançar batida em data futura.');
    if ($data < ptJanelaEdicaoMin($hoje)) erro('Só é possível ajustar a folha atual e a anterior. Fale com o gestor.');
    if (!in_array($tipo, PT_ORDEM, true)) erro('Tipo de batida inválido.');
    if (ptMin($hora) === null) erro('Informe o horário (HH:MM).');
    if (ptLen($just) < 5) erro('Informe a justificativa (mínimo de 5 caracteres).');
    if (ptLen($just) > 500) erro('Justificativa muito longa (máximo de 500 caracteres).');
    if (($ad = ptAdmissao($pdo, $nome)) && $data < $ad) erro('Sua data de admissão é ' . date('d/m/Y', strtotime($ad)) . '. Não é possível lançar ponto antes dela.');
    $mom = $data . ' ' . $hora . ':00';
    if ($data === $hoje && strtotime($mom) > time()) erro('O horário não pode ser futuro.');
    $pdo->beginTransaction();
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:n))")->execute(['n' => $nome]);
    $bat = ptBatidasDoDia($pdo, $nome, $data);
    if (in_array($tipo, array_column(ptAtivas($bat), 'tipo'), true)) { $pdo->rollBack(); erro('Já existe "' . PT_LABEL[$tipo] . '" neste dia. Aguarde a decisão do gestor ou fale com ele.'); }
    $ativosTp = array_column(ptAtivas($bat), 'tipo');
    if ($tipo === 'EXTRA_ENTRADA' && !in_array('SAIDA', $ativosTp, true)) { $pdo->rollBack(); erro('A hora extra começa depois do fim de expediente. Registre antes o "Fim de expediente".'); }
    if ($tipo === 'EXTRA_SAIDA' && !in_array('EXTRA_ENTRADA', $ativosTp, true)) { $pdo->rollBack(); erro('Inclua antes o "Início da hora extra".'); }
    if ($e = ptValidarOrdem($bat, $tipo, $mom)) { $pdo->rollBack(); erro($e); }
    $ins = $pdo->prepare("INSERT INTO ponto_batidas (nome, data_ref, tipo, momento, origem, justificativa, status)
                   VALUES (:n, :d, :t, :m, 'manual', :j, 'pendente') RETURNING id");
    $ins->execute(['n' => $nome, 'd' => $data, 't' => $tipo, 'm' => $mom, 'j' => $just]);
    $novoId = (int)$ins->fetchColumn();
    $pdo->commit();
    evento($pdo, $novoId, $nome, $data, 'INCLUSAO_SOLICITADA', $tipo, $mom, atorDe($nome), $just);
    auditar($pdo, atorDe($nome), 'PONTO', "Solicitou batida manual $tipo $hora em $data: $just" . porConta($nome));
    resp(['sucesso' => true]);
}

case 'ponto_folha': {
    $nome = exigeColab($pdo); $regra = ptRegra($pdo, $nome); $c = colab($pdo, $nome);
    $atual = ptFolhaRefDe(date('Y-m-d'));
    $ref = $_GET['ref'] ?? $atual;
    if (!ptFolhaRefValida($ref)) erro('Folha inválida.');
    if ($ref > $atual) erro('Esta folha ainda não começou.');
    resp(['sucesso' => true, 'nome' => $nome, 'folha' => ptFolha($pdo, $regra, $nome, $ref, null, null), 'banco' => ptBancoHoras($pdo, $nome), 'rotulos' => PT_LABEL, 'agora' => date('Y-m-d H:i:s'),
          'gestor_edita' => ptGestorEdita($pdo) || $nome === ($_SESSION['ponto_nome'] ?? null)]);
}
case 'ponto_atestado_enviar': { // tipo 'dia' (data início/fim + atestado) ou 'consulta' (dia, hora início/fim + comprovante de comparecimento)
    $nome = exigeColab($pdo); $tipo = $in['tipo'] ?? ''; $hoje = date('Y-m-d'); $obs = trim((string)($in['observacao'] ?? ''));
    if (!in_array($tipo, ['dia', 'consulta'], true)) erro('Tipo de atestado inválido.');
    $ini = $in['data_ini'] ?? ''; $fim = $tipo === 'dia' ? ($in['data_fim'] ?? '') : $ini; $hi = $hf = null;
    if (!ptDataValida($ini) || !ptDataValida($fim)) erro('Informe as datas.');
    if ($fim < $ini) erro('A data final não pode ser anterior à data inicial.');
    if ($ini < ptJanelaEdicaoMin($hoje)) erro('Só é possível enviar atestado da folha atual e da anterior. Fale com o gestor.');
    if ($tipo === 'dia' && (strtotime($fim) - strtotime($ini)) / 86400 > 59) erro('O atestado pode ter no máximo 60 dias.');
    if ($tipo === 'dia' && $fim > date('Y-m-d', strtotime($hoje . ' +60 days'))) erro('Data final muito distante.');
    if ($tipo === 'consulta') {
        if ($ini > $hoje) erro('A consulta precisa já ter acontecido. Envie o comprovante de comparecimento depois dela.');
        $hi = $in['hora_ini'] ?? ''; $hf = $in['hora_fim'] ?? '';
        if (ptMin($hi) === null || ptMin($hf) === null) erro('Informe a hora de início e a hora de fim da consulta.');
        if (ptMin($hf) <= ptMin($hi)) erro('A hora final precisa ser depois da hora inicial.');
    }
    if (ptLen($obs) > 300) erro('Observação muito longa (máximo de 300 caracteres).');
    $arq = $in['arquivo'] ?? '';
    if (!is_string($arq) || !preg_match('#^data:(image/jpeg|image/png|application/pdf);base64,([A-Za-z0-9+/=]+)$#', $arq, $mm)) erro('Anexe o ' . ($tipo === 'dia' ? 'atestado médico' : 'atestado de comparecimento') . ' (PDF, JPG ou PNG).');
    $bin = base64_decode($mm[2], true);
    if ($bin === false || strlen($bin) < 100) erro('Arquivo inválido.');
    if (strlen($bin) > 4 * 1024 * 1024) erro('O arquivo é grande demais (máximo de 4 MB).');
    $ok = ($mm[1] === 'application/pdf' && strncmp($bin, '%PDF', 4) === 0) || ($mm[1] === 'image/jpeg' && strncmp($bin, "\xFF\xD8", 2) === 0) || ($mm[1] === 'image/png' && strncmp($bin, "\x89PNG", 4) === 0);
    if (!$ok) erro('O conteúdo do arquivo não corresponde ao formato informado.');
    $anome = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '', (string)($in['arquivo_nome'] ?? 'atestado')), 0, 120) ?: 'atestado';
    $pdo->beginTransaction();
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:n))")->execute(['n' => $nome]);
    $q = $pdo->prepare("SELECT tipo, hora_ini, hora_fim FROM ponto_atestados WHERE nome = :n AND status IN ('pendente','aprovada') AND data_ini <= :f AND data_fim >= :i");
    $q->execute(['n' => $nome, 'i' => $ini, 'f' => $fim]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $choca = $tipo === 'dia' || $x['tipo'] === 'dia' || (ptMin(substr($x['hora_ini'], 0, 5)) < ptMin($hf) && ptMin(substr($x['hora_fim'], 0, 5)) > ptMin($hi));
        if ($choca) { $pdo->rollBack(); erro('Já existe um atestado enviado que cobre este período. Aguarde a decisão do gestor ou fale com ele.'); }
    }
    $ins = $pdo->prepare("INSERT INTO ponto_atestados (nome, tipo, data_ini, data_fim, hora_ini, hora_fim, observacao, arquivo_nome, arquivo_tipo, arquivo)
                          VALUES (:n, :t, :i, :f, :hi, :hf, :o, :an, :at, :a) RETURNING id");
    $ins->execute(['n' => $nome, 't' => $tipo, 'i' => $ini, 'f' => $fim, 'hi' => $hi, 'hf' => $hf, 'o' => $obs ?: null, 'an' => $anome, 'at' => $mm[1], 'a' => $arq]);
    $novoId = (int)$ins->fetchColumn();
    $pdo->commit();
    $det = ptRotuloAtestado($tipo) . ': ' . ($tipo === 'dia' ? "de $ini a $fim" : "$ini das $hi às $hf") . ($obs ? " — $obs" : '');
    evento($pdo, $novoId, $nome, $ini, 'ATESTADO_SOLICITADO', $tipo === 'dia' ? 'ATESTADO_DIA' : 'ATESTADO_CONS', $ini . ' ' . ($hi ?: '00:00') . ':00', atorDe($nome), $det);
    auditar($pdo, atorDe($nome), 'PONTO', "Enviou atestado: $det" . porConta($nome));
    resp(['sucesso' => true, 'id' => $novoId]);
}
case 'ponto_abono_enviar': { // Solicitar abono: motivo + dia ou período (dia completo ou horas) + observação + anexo opcional
    $nome = exigeColab($pdo); $hoje = date('Y-m-d'); $motivo = trim((string)($in['motivo'] ?? '')); $obs = trim((string)($in['observacao'] ?? ''));
    if (!in_array($motivo, PT_MOTIVOS_ABONO, true)) erro('Selecione o motivo.');
    $periodo = ($in['modo'] ?? 'dia') === 'periodo';
    $ini = $in['data_ini'] ?? ''; $fim = $periodo ? ($in['data_fim'] ?? '') : $ini;
    $completo = $periodo ? true : !empty($in['dia_completo']); $hi = $hf = null;
    if (!ptDataValida($ini) || !ptDataValida($fim)) erro('Informe ' . ($periodo ? 'as datas.' : 'a data.'));
    if ($fim < $ini) erro('A data final não pode ser anterior à data inicial.');
    if ($ini < ptJanelaEdicaoMin($hoje)) erro('Só é possível solicitar abono da folha atual e da anterior. Fale com o gestor.');
    if ((strtotime($fim) - strtotime($ini)) / 86400 > 59) erro('O período pode ter no máximo 60 dias.');
    if ($fim > date('Y-m-d', strtotime($hoje . ' +60 days'))) erro('Data final muito distante.');
    if (!$completo) {
        $hi = $in['hora_ini'] ?? ''; $hf = $in['hora_fim'] ?? '';
        if (ptMin($hi) === null || ptMin($hf) === null) erro('Informe a hora inicial e a hora final.');
        if (ptMin($hf) <= ptMin($hi)) erro('A hora final precisa ser depois da hora inicial.');
    }
    if ($obs === '') erro('Preencha a observação.');
    if (ptLen($obs) > 1000) erro('Observação muito longa (máximo de 1000 caracteres).');
    $arq = $in['arquivo'] ?? null; $anome = null; $atipo = null;
    if ($arq !== null && $arq !== '') {
        if (!is_string($arq) || !preg_match('#^data:(image/jpeg|image/png|application/pdf);base64,([A-Za-z0-9+/=]+)$#', $arq, $mm)) erro('Anexo inválido. Use PDF, JPG ou PNG.');
        $bin = base64_decode($mm[2], true);
        if ($bin === false || strlen($bin) < 100) erro('Arquivo inválido.');
        if (strlen($bin) > 4 * 1024 * 1024) erro('O arquivo é grande demais (máximo de 4 MB).');
        $ok = ($mm[1] === 'application/pdf' && strncmp($bin, '%PDF', 4) === 0) || ($mm[1] === 'image/jpeg' && strncmp($bin, "\xFF\xD8", 2) === 0) || ($mm[1] === 'image/png' && strncmp($bin, "\x89PNG", 4) === 0);
        if (!$ok) erro('O conteúdo do arquivo não corresponde ao formato informado.');
        $atipo = $mm[1]; $anome = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '', (string)($in['arquivo_nome'] ?? 'anexo')), 0, 120) ?: 'anexo';
    } else $arq = null;
    $tipo = $completo ? 'dia' : 'consulta';
    $pdo->beginTransaction();
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:n))")->execute(['n' => $nome]);
    $q = $pdo->prepare("SELECT tipo, hora_ini, hora_fim FROM ponto_atestados WHERE nome = :n AND status IN ('pendente','aprovada') AND data_ini <= :f AND data_fim >= :i");
    $q->execute(['n' => $nome, 'i' => $ini, 'f' => $fim]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $choca = $tipo === 'dia' || $x['tipo'] === 'dia' || (ptMin(substr($x['hora_ini'], 0, 5)) < ptMin($hf) && ptMin(substr($x['hora_fim'], 0, 5)) > ptMin($hi));
        if ($choca) { $pdo->rollBack(); erro('Já existe um abono solicitado que cobre este período. Aguarde a decisão do gestor ou fale com ele.'); }
    }
    $ins = $pdo->prepare("INSERT INTO ponto_atestados (nome, tipo, motivo, data_ini, data_fim, hora_ini, hora_fim, observacao, arquivo_nome, arquivo_tipo, arquivo)
                          VALUES (:n, :t, :mo, :i, :f, :hi, :hf, :o, :an, :at, :a) RETURNING id");
    $ins->execute(['n' => $nome, 't' => $tipo, 'mo' => $motivo, 'i' => $ini, 'f' => $fim, 'hi' => $hi, 'hf' => $hf, 'o' => $obs, 'an' => $anome, 'at' => $atipo, 'a' => $arq]);
    $novoId = (int)$ins->fetchColumn();
    $pdo->commit();
    $det = "Abono ($motivo): " . ($completo ? ($ini === $fim ? $ini : "de $ini a $fim") : "$ini das $hi às $hf") . " — $obs";
    evento($pdo, $novoId, $nome, $ini, 'ATESTADO_SOLICITADO', $completo ? 'ATESTADO_DIA' : 'ATESTADO_CONS', $ini . ' ' . ($hi ?: '00:00') . ':00', atorDe($nome), $det);
    auditar($pdo, atorDe($nome), 'PONTO', "Solicitou $det" . porConta($nome));
    resp(['sucesso' => true, 'id' => $novoId]);
}
case 'ponto_espelho_pdf': { // espelho de ponto da folha (uma única página A4)
    $nome = exigeColab($pdo); $regra = ptRegra($pdo, $nome);
    $ref = $_GET['ref'] ?? ptFolhaRefDe(date('Y-m-d'));
    if (!ptFolhaRefValida($ref)) erro('Folha inválida.');
    if ($ref > ptFolhaRefDe(date('Y-m-d'))) erro('Esta folha ainda não começou.');
    require_once '/var/www/lib/ponto_pdf.php';
    $bin = ptEspelhoPdf($pdo, $regra, $nome, $ref);
    auditar($pdo, atorDe($nome), 'PONTO', "Baixou o espelho de ponto " . substr($ref, 5, 2) . '/' . substr($ref, 0, 4) . porConta($nome));
    header_remove('Content-Type'); header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="folha-ponto-' . substr($ref, 5, 2) . '-' . substr($ref, 0, 4) . '.pdf"');
    header('Content-Length: ' . strlen($bin)); echo $bin; exit;
}
case 'ponto_atestado_listar': {
    $nome = exigeColab($pdo);
    $st = $pdo->prepare("SELECT id, tipo, motivo, (arquivo IS NOT NULL) AS tem_anexo, to_char(data_ini,'YYYY-MM-DD') AS data_ini, to_char(data_fim,'YYYY-MM-DD') AS data_fim, to_char(hora_ini,'HH24:MI') AS hora_ini,
                                to_char(hora_fim,'HH24:MI') AS hora_fim, status, motivo_decisao, arquivo_nome, to_char(criado_em,'YYYY-MM-DD HH24:MI') AS criado_em
                         FROM ponto_atestados WHERE nome = :n ORDER BY data_ini DESC, id DESC LIMIT 20");
    $st->execute(['n' => $nome]); $l = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($l as &$r) { $r['id'] = (int)$r['id']; $r['rotulo'] = ptRotuloAtestado($r['tipo'], $r['motivo']); }
    resp(['sucesso' => true, 'itens' => $l]);
}
case 'ponto_atestado_arquivo': { // devolve o anexo (somente o dono ou um gestor)
    exigeLogin(); $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT nome, arquivo, arquivo_tipo, arquivo_nome FROM ponto_atestados WHERE id = :i"); $st->execute(['i' => $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (!ehGestor() && ($_SESSION['ponto_nome'] ?? null) !== $r['nome'])) erro('Anexo não encontrado.', 404);
    if (!is_string($r['arquivo']) || !preg_match('#^data:([a-z/]+);base64,(.+)$#s', $r['arquivo'], $m)) erro('Esta solicitação não tem anexo.', 404);
    header('Content-Type: ' . $m[1]); header('Content-Disposition: inline; filename="' . str_replace('"', '', $r['arquivo_nome']) . '"');
    header("Content-Security-Policy: default-src 'none'; img-src data: 'self'; style-src 'unsafe-inline'");
    echo base64_decode($m[2]); exit;
}
case 'ponto_remover': {
    $nome = exigeColab($pdo); $id = (int)($in['id'] ?? 0); $just = trim((string)($in['justificativa'] ?? ''));
    if (ptLen($just) < 5) erro('Informe a justificativa (mínimo de 5 caracteres).');
    if (ptLen($just) > 500) erro('Justificativa muito longa (máximo de 500 caracteres).');
    $st = $pdo->prepare("SELECT tipo, status, remocao_status, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h FROM ponto_batidas WHERE id = :id AND nome = :n");
    $st->execute(['id' => $id, 'n' => $nome]);
    $b = $st->fetch(PDO::FETCH_ASSOC);
    if (!$b) erro('Batida não encontrada.');
    if ($b['d'] < ptJanelaEdicaoMin(date('Y-m-d'))) erro('Só é possível ajustar a folha atual e a anterior. Fale com o gestor.');
    if ($b['status'] === 'pendente') erro('Esta inclusão ainda aguarda decisão. Cancele a solicitação em vez de pedir remoção.');
    if (!in_array($b['status'], ['valida', 'aprovada'], true)) erro('Esta batida não está mais ativa.');
    if ($b['remocao_status'] === 'pendente') erro('Já existe um pedido de remoção para esta batida.');
    $pdo->prepare("UPDATE ponto_batidas SET remocao_status = 'pendente', remocao_justificativa = :j, remocao_em = now(),
                   remocao_decidido_por = NULL, remocao_decidido_em = NULL, remocao_motivo = NULL WHERE id = :id")->execute(['j' => $just, 'id' => $id]);
    evento($pdo, $id, $nome, $b['d'], 'REMOCAO_SOLICITADA', $b['tipo'], $b['d'] . ' ' . $b['h'] . ':00', atorDe($nome), $just);
    auditar($pdo, atorDe($nome), 'PONTO', "Solicitou remoção da batida {$b['tipo']} {$b['h']} de {$b['d']}: $just" . porConta($nome));
    resp(['sucesso' => true]);
}
case 'ponto_cancelar': {
    $nome = exigeColab($pdo); $id = (int)($in['id'] ?? 0); $solic = $in['solic'] ?? 'inclusao';
    if (!in_array($solic, ['inclusao', 'remocao'], true)) erro('Tipo de solicitação inválido.');
    if ($solic === 'inclusao') {
        $st = $pdo->prepare("UPDATE ponto_batidas SET status = 'cancelada', decidido_por = :u, decidido_em = now() WHERE id = :id AND nome = :n2 AND status = 'pendente' RETURNING tipo, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h");
        $st->execute(['u' => atorDe($nome), 'id' => $id, 'n2' => $nome]);
    } else {
        $st = $pdo->prepare("UPDATE ponto_batidas SET remocao_status = NULL, remocao_justificativa = NULL, remocao_em = NULL WHERE id = :id AND nome = :n AND remocao_status = 'pendente' RETURNING tipo, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h");
        $st->execute(['id' => $id, 'n' => $nome]);
    }
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) erro('Solicitação não encontrada ou já decidida.');
    evento($pdo, $id, $nome, $r['d'], $solic === 'inclusao' ? 'INCLUSAO_CANCELADA' : 'REMOCAO_CANCELADA', $r['tipo'], $r['d'] . ' ' . $r['h'] . ':00', atorDe($nome), null);
    auditar($pdo, atorDe($nome), 'PONTO', "Cancelou a solicitação de " . ($solic === 'inclusao' ? 'inclusão' : 'remoção') . " da batida {$r['tipo']} {$r['h']} de {$r['d']}" . porConta($nome));
    resp(['sucesso' => true]);
}

case 'ponto_extra_manual': { // dia sem expediente (fim de semana/feriado/folga): inclusão de entrada e saída da hora extra
    $nome = exigeColab($pdo); $regra = ptRegra($pdo, $nome); $data = $in['data'] ?? ''; $just = trim((string)($in['justificativa'] ?? ''));
    if (!ptDataValida($data)) erro('Data inválida.');
    $hoje = date('Y-m-d');
    if ($data > $hoje) erro('Não é possível lançar hora extra em data futura.');
    if (($ad = ptAdmissao($pdo, $nome)) && $data < $ad) erro('Sua data de admissão é ' . date('d/m/Y', strtotime($ad)) . '. Não é possível lançar hora extra antes dela.');
    if ($data < ptJanelaEdicaoMin($hoje)) erro('Só é possível ajustar a folha atual e a anterior. Fale com o gestor.');
    if (ptLen($just) < 5) erro('Informe a justificativa (mínimo de 5 caracteres).');
    if (ptLen($just) > 500) erro('Justificativa muito longa (máximo de 500 caracteres).');
    $pdo->beginTransaction();
    $pdo->prepare("SELECT pg_advisory_xact_lock(hashtext(:n))")->execute(['n' => $nome]);
    $s = ptStatusDia($pdo, $regra, $nome, $data);
    if ($s['tem_regra'] && !$s['sem_obrigacao']) { $pdo->rollBack(); erro('Este dia tem expediente. Use as batidas do dia para ajustar os horários.'); }
    $tem = array_column(ptAtivas($s['batidas']), 'tipo'); $novos = [];
    foreach (['ENTRADA' => 'inicio', 'SAIDA' => 'fim'] as $tp => $campo) {
        if (in_array($tp, $tem, true)) continue;
        $h = $in[$campo] ?? '';
        if (ptMin($h) === null) { $pdo->rollBack(); erro('Informe o horário de ' . ($tp === 'ENTRADA' ? 'início' : 'fim') . ' (HH:MM).'); }
        $novos[$tp] = $h;
    }
    if (!$novos) { $pdo->rollBack(); erro('Este dia já tem entrada e saída registradas.'); }
    foreach ($novos as $tp => $h) {
        $mom = $data . ' ' . $h . ':00';
        if ($data === $hoje && strtotime($mom) > time()) { $pdo->rollBack(); erro('O horário não pode ser futuro.'); }
        $bat = ptBatidasDoDia($pdo, $nome, $data);
        if ($e = ptValidarOrdem($bat, $tp, $mom)) { $pdo->rollBack(); erro($e); }
        $ins = $pdo->prepare("INSERT INTO ponto_batidas (nome, data_ref, tipo, momento, origem, justificativa, status)
                              VALUES (:n, :d, :t, :m, 'manual', :j, 'pendente') RETURNING id");
        $ins->execute(['n' => $nome, 'd' => $data, 't' => $tp, 'm' => $mom, 'j' => $just]);
        $ids[$tp] = (int)$ins->fetchColumn();
    }
    $pdo->commit();
    foreach ($novos as $tp => $h) evento($pdo, $ids[$tp], $nome, $data, 'INCLUSAO_SOLICITADA', $tp, $data . ' ' . $h . ':00', atorDe($nome), 'Hora extra em dia sem expediente: ' . $just);
    auditar($pdo, atorDe($nome), 'PONTO', "Solicitou hora extra manual em $data (dia sem expediente): " . implode(' / ', array_map(function ($t, $h) { return "$t $h"; }, array_keys($novos), $novos)) . " — $just");
    resp(['sucesso' => true, 'extra_min' => ptExtraDia($pdo, $regra, $nome, $data)]);
}
case 'ponto_dia_historico': { // histórico do colaborador: cada solicitação/decisão/batida é um registro (sem IP, local nem dados faciais)
    $nome = exigeColab($pdo); $data = $_GET['data'] ?? '';
    if (!ptDataValida($data) || $data > date('Y-m-d')) erro('Data inválida.');
    $gv = trim((string)($_GET['alvo'] ?? '')) !== ''; // gestor na Apuração: vê também IP, aparelho, local e distância facial
    $st = $pdo->prepare("SELECT id, evento, tipo, to_char(momento,'YYYY-MM-DD HH24:MI:SS') AS momento, usuario,
                                " . ($gv ? "detalhes, ip, agente," : "CASE WHEN evento = 'BATIDA_FACIAL' THEN 'Reconhecimento facial' ELSE detalhes END AS detalhes,") . "
                                to_char(criado_em,'YYYY-MM-DD HH24:MI:SS') AS criado_em
                         FROM ponto_eventos WHERE nome = :n AND data_ref = :d ORDER BY criado_em, id");
    $st->execute(['n' => $nome, 'd' => $data]);
    resp(['sucesso' => true, 'data' => $data, 'nome' => $nome, 'auditoria' => $gv, 'eventos' => $st->fetchAll(PDO::FETCH_ASSOC), 'rotulos' => PT_LABEL]);
}
case 'ponto_vidpol_add': {
    $nome = exigeColab($pdo); $regra = ptRegra($pdo, $nome); $data = $in['data'] ?? '';
    if (!ptDataValida($data)) erro('Data inválida.');
    $hoje = date('Y-m-d');
    if ($data > $hoje || $data < ptJanelaEdicaoMin($hoje)) erro('Só é possível informar VIDPOL na folha atual e na anterior.');
    if (ptExtraDia($pdo, $regra, $nome, $data) <= 0) erro('Este dia não tem hora extra.');
    $pp = $pdo->prepare("SELECT count(*) FROM ponto_batidas WHERE nome = :n AND data_ref = :d AND (status = 'pendente' OR remocao_status = 'pendente')"); $pp->execute(['n' => $nome, 'd' => $data]);
    if ((int)$pp->fetchColumn() > 0) erro('Aguarde o gestor aprovar a batida deste dia para informar o VIDPOL ou a justificativa da hora extra.');
    $txt = is_array($in['vidpol'] ?? null) ? implode(' ', $in['vidpol']) : (string)($in['vidpol'] ?? '');
    $lista = array_values(array_unique(preg_split('/[^0-9A-Za-z]+/', trim($txt), -1, PREG_SPLIT_NO_EMPTY)));
    if (!$lista) erro('Informe o VIDPOL.');
    foreach ($lista as $v) if (!preg_match('/^\d{6}$/', $v)) erro('VIDPOL inválido: "' . $v . '". Use 6 dígitos numéricos.');
    $q = $pdo->prepare("SELECT count(*) FROM ponto_vidpol WHERE nome = :n AND data_ref = :d"); $q->execute(['n' => $nome, 'd' => $data]);
    if ((int)$q->fetchColumn() + count($lista) > 30) erro('Limite de 30 VIDPOL por dia.');
    $ins = $pdo->prepare("INSERT INTO ponto_vidpol (nome, data_ref, vidpol) VALUES (:n, :d, :v) ON CONFLICT DO NOTHING");
    foreach ($lista as $v) $ins->execute(['n' => $nome, 'd' => $data, 'v' => $v]);
    evento($pdo, null, $nome, $data, 'VIDPOL_INCLUIDO', null, null, atorDe($nome), implode(', ', $lista));
    auditar($pdo, atorDe($nome), 'PONTO', "Informou VIDPOL da hora extra de $data: " . implode(', ', $lista) . porConta($nome));
    $q = $pdo->prepare("SELECT id, vidpol FROM ponto_vidpol WHERE nome = :n AND data_ref = :d ORDER BY id"); $q->execute(['n' => $nome, 'd' => $data]);
    resp(['sucesso' => true, 'vidpols' => array_map(function ($r) { return ['id' => (int)$r['id'], 'vidpol' => $r['vidpol']]; }, $q->fetchAll(PDO::FETCH_ASSOC))]);
}
case 'ponto_he_justificar': { // hora extra sem VIDPOL: o colaborador informa a justificativa; a hora extra só vai para a aprovação do gestor depois disso
    $nome = exigeColab($pdo); $regra = ptRegra($pdo, $nome); $data = $in['data'] ?? ''; $just = trim((string)($in['justificativa'] ?? ''));
    if (!ptDataValida($data)) erro('Data inválida.');
    $hoje = date('Y-m-d');
    if ($data > $hoje || $data < ptJanelaEdicaoMin($hoje)) erro('Só é possível justificar a hora extra da folha atual e da anterior.');
    if (ptExtraDia($pdo, $regra, $nome, $data) <= 0) erro('Este dia não tem hora extra.');
    $pp = $pdo->prepare("SELECT count(*) FROM ponto_batidas WHERE nome = :n AND data_ref = :d AND (status = 'pendente' OR remocao_status = 'pendente')"); $pp->execute(['n' => $nome, 'd' => $data]);
    if ((int)$pp->fetchColumn() > 0) erro('Aguarde o gestor aprovar a batida deste dia para informar o VIDPOL ou a justificativa da hora extra.');
    if (ptLen($just) < 5) erro('Informe a justificativa (mínimo de 5 caracteres).');
    if (ptLen($just) > 500) erro('Justificativa muito longa (máximo de 500 caracteres).');
    ptSincronizarHE($pdo, $regra, $nome, $data);
    $st = $pdo->prepare("UPDATE ponto_he_dia SET justificativa = :j WHERE nome = :n AND data_ref = :d RETURNING id");
    $st->execute(['j' => $just, 'n' => $nome, 'd' => $data]);
    if (!$st->fetchColumn()) erro('Este dia não tem hora extra para justificar.');
    evento($pdo, null, $nome, $data, 'HE_JUSTIFICADA', null, null, atorDe($nome), $just);
    auditar($pdo, atorDe($nome), 'PONTO', "Justificou a hora extra de $data (sem VIDPOL): $just" . porConta($nome));
    resp(['sucesso' => true]);
}
case 'ponto_vidpol_del': {
    $nome = exigeColab($pdo); $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare("DELETE FROM ponto_vidpol WHERE id = :id AND nome = :n RETURNING vidpol, to_char(data_ref,'YYYY-MM-DD') AS d");
    $st->execute(['id' => $id, 'n' => $nome]); $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) erro('VIDPOL não encontrado.');
    evento($pdo, null, $nome, $r['d'], 'VIDPOL_REMOVIDO', null, null, atorDe($nome), $r['vidpol']);
    auditar($pdo, atorDe($nome), 'PONTO', "Removeu o VIDPOL {$r['vidpol']} da hora extra de {$r['d']}" . porConta($nome));
    resp(['sucesso' => true]);
}

// =================== GESTOR ===================
case 'ponto_banco_folga': { // colaborador pede folga abatendo do banco de horas (o gestor aprova)
    $nome = exigeColab($pdo); $data = (string)($in['data'] ?? ''); $just = trim((string)($in['justificativa'] ?? ''));
    if (!ptDataValida($data)) erro('Informe o dia da folga.');
    $hoje = date('Y-m-d'); if ($data < $hoje) erro('A folga deve ser hoje ou em uma data futura.');
    if (ptLen($just) > 300) erro('Observação muito longa (máximo de 300 caracteres).');
    $regra = ptRegra($pdo, $nome); if (empty($regra['banco_horas'])) erro('O banco de horas não está habilitado.');
    $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
    if (!$r || ptDiaSemObrigacao($pdo, $nome, $data)) erro('Esse dia não tem expediente.');
    $q = $pdo->prepare("SELECT 1 FROM ponto_banco_folgas WHERE nome = :n AND data = :d AND status IN ('pendente','aprovada')"); $q->execute(['n' => $nome, 'd' => $data]);
    if ($q->fetchColumn()) erro('Já existe uma folga solicitada para esse dia.');
    if (ptAtivas(ptBatidasDoDia($pdo, $nome, $data))) erro('Já há batidas registradas nesse dia.');
    $min = ptPrevistoMin($regra, $data); $b = ptBancoHoras($pdo, $nome); $prec = max(PT_BANCO_MIN_FOLGA, $min);
    if ((int)$b['disponivel_min'] < $prec) erro('Saldo insuficiente no banco de horas: disponível ' . ptHoras((int)$b['disponivel_min']) . '; é preciso ter pelo menos ' . ptHoras($prec) . '.');
    $st = $pdo->prepare("INSERT INTO ponto_banco_folgas (nome, data, minutos, justificativa) VALUES (:n, :d, :m, :j) RETURNING id");
    $st->execute(['n' => $nome, 'd' => $data, 'm' => $min, 'j' => $just ?: null]); $id = (int)$st->fetchColumn();
    evento($pdo, $id, $nome, $data, 'FOLGA_SOLICITADA', 'FOLGA_BANCO', $data . ' 00:00:00', atorDe($nome), $just ?: null);
    auditar($pdo, atorDe($nome), 'PONTO', "Solicitou folga do banco de horas em $data (" . ptHoras($min) . ')' . porConta($nome));
    resp(['sucesso' => true]);
}
case 'ponto_banco_folga_cancelar': { // o colaborador desiste de um pedido de folga ainda em análise
    $nome = exigeColab($pdo); $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare("UPDATE ponto_banco_folgas SET status = 'cancelada', decidido_por = :u, decidido_em = now(), motivo_decisao = 'Cancelada pelo colaborador'
                         WHERE id = :id AND nome = :n AND status = 'pendente' RETURNING to_char(data,'YYYY-MM-DD')");
    $st->execute(['u' => atorDe($nome), 'id' => $id, 'n' => $nome]); $d = $st->fetchColumn();
    if (!$d) erro('Solicitação não encontrada ou já decidida.');
    evento($pdo, $id, $nome, $d, 'FOLGA_CANCELADA', 'FOLGA_BANCO', $d . ' 00:00:00', atorDe($nome), null);
    resp(['sucesso' => true]);
}
case 'ponto_admin_fiscal': { // dados do empregador/programa para os arquivos da Portaria 671
    exigeGestor();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($e = ptFiscalSalvar($pdo, $in)) erro($e);
        auditar($pdo, $_SESSION['usuario'], 'PONTO', 'Atualizou os dados fiscais do ponto (Portaria 671)');
    }
    resp(['sucesso' => true, 'cfg' => ptFiscalCfg($pdo), 'sem_cpf' => ptFiscalSemCpf($pdo)]);
}
case 'ponto_admin_afd':
case 'ponto_admin_aej': { // download do AFD (marcações originais do REP-P) ou do AEJ (jornada tratada)
    exigeGestor();
    $ini = (string)($_GET['inicio'] ?? ''); $fim = (string)($_GET['fim'] ?? '');
    if (!ptDataValida($ini) || !ptDataValida($fim) || $ini > $fim) erro('Período inválido.');
    if ((strtotime($fim) - strtotime($ini)) / 86400 > 366) erro('Escolha um período de até 366 dias.');
    @set_time_limit(300);
    try { $arq = $action === 'ponto_admin_afd' ? ptAfdGerar($pdo, $ini, $fim) : ptAejGerar($pdo, $ini, $fim); } catch (Exception $e) { erro($e->getMessage()); }
    auditar($pdo, $_SESSION['usuario'], 'PONTO', 'Gerou o ' . ($action === 'ponto_admin_afd' ? 'AFD' : 'AEJ') . " de $ini a $fim");
    header_remove('Content-Type'); header('Content-Type: text/plain; charset=ISO-8859-1');
    header('Content-Disposition: attachment; filename="' . $arq['nome'] . '"');
    if (!empty($arq['sem_cpf'])) header('X-Sem-CPF: ' . rawurlencode(implode(', ', $arq['sem_cpf'])));
    echo $arq['conteudo']; exit;
}
case 'ponto_admin_regra': { // sem ?nome= é a regra padrão; com ?nome= é a regra individual do colaborador
    exigeGestor(); $alvo = trim($_GET['nome'] ?? '');
    if ($alvo !== '') {
        $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $alvo]);
        if (!$st->fetchColumn()) erro('Colaborador não encontrado.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if ($alvo === '') {
            if ($e = ptRegraValidar($in)) erro($e);
            ptRegraSalvar($pdo, $in);
            auditar($pdo, $_SESSION['usuario'], 'PONTO', 'Alterou a regra de horário padrão do ponto (flexibilidade ' . (int)($in['flex_min'] ?? 0) . ' min)');
        } elseif (!empty($in['padrao'])) {
            ptRegraSalvarColab($pdo, $alvo, null);
            auditar($pdo, $_SESSION['usuario'], 'PONTO', "Voltou $alvo para a regra de horário padrão");
        } else {
            if ($e = ptRegraValidar($in)) erro($e);
            ptRegraSalvarColab($pdo, $alvo, $in);
            auditar($pdo, $_SESSION['usuario'], 'PONTO', "Definiu regra de horário individual de $alvo (flexibilidade " . (int)($in['flex_min'] ?? 0) . ' min)');
        }
        resp(['sucesso' => true]);
    }
    $r = ptRegra($pdo, $alvo === '' ? null : $alvo); $ind = !empty($r['individual']); unset($r['individual']);
    resp(['sucesso' => true, 'regra' => $r, 'individual' => $ind, 'flex_max' => PT_FLEX_MAX]);
}
case 'ponto_admin_colaboradores': {
    exigeGestor();
    $st = $pdo->query("SELECT f.nome, f.nome_completo, f.setor, u.usuario, (p.descritor IS NOT NULL) AS cadastrado, COALESCE(p.ativo, TRUE) AS ativo, (p.regra IS NOT NULL) AS regra_individual, p.email
                       FROM funcionarios f
                       LEFT JOIN ponto_colaboradores p ON p.nome = f.nome
                       LEFT JOIN LATERAL (SELECT usuario FROM usuarios WHERE upper(nome_completo) IN (upper(f.nome), upper(f.nome_completo)) ORDER BY usuario LIMIT 1) u ON TRUE
                       ORDER BY f.nome");
    $l = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($l as &$r) { foreach (['cadastrado', 'ativo', 'regra_individual'] as $k) $r[$k] = (bool)$r[$k]; $r['tem_login'] = !empty($r['usuario']); }
    resp(['sucesso' => true, 'itens' => $l]);
}
case 'ponto_admin_resetar_rosto': {
    exigeGestor(); $nome = trim($in['nome'] ?? '');
    $pdo->prepare("UPDATE ponto_colaboradores SET descritor = NULL, foto_cadastro = NULL, cadastrado_em = NULL WHERE nome = :n")->execute(['n' => $nome]);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', "Resetou o cadastro facial de $nome");
    resp(['sucesso' => true]);
}
case 'ponto_admin_ativar': {
    exigeGestor(); $nome = trim($in['nome'] ?? '');
    $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
    if (!$st->fetchColumn()) erro('Colaborador não encontrado.');
    $pdo->prepare("INSERT INTO ponto_colaboradores (nome, ativo) VALUES (:n, :a) ON CONFLICT (nome) DO UPDATE SET ativo = EXCLUDED.ativo")
        ->execute(['a' => !empty($in['ativo']) ? 'true' : 'false', 'n' => $nome]);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', (!empty($in['ativo']) ? 'Ativou' : 'Desativou') . " o ponto de $nome");
    resp(['sucesso' => true]);
}
case 'ponto_admin_pendencias': {
    exigeGestor();
    $st = $pdo->query("SELECT id, nome, to_char(data_ref,'YYYY-MM-DD') AS data, tipo, to_char(momento,'HH24:MI') AS hora, justificativa,
                              to_char(criado_em,'YYYY-MM-DD HH24:MI') AS criado_em, 'inclusao' AS solic, origem, false AS tem_anexo
                       FROM ponto_batidas WHERE status = 'pendente'
                       UNION ALL
                       SELECT id, nome, to_char(data_ref,'YYYY-MM-DD'), tipo, to_char(momento,'HH24:MI'), remocao_justificativa,
                              to_char(remocao_em,'YYYY-MM-DD HH24:MI'), 'remocao', origem, false
                       FROM ponto_batidas WHERE remocao_status = 'pendente'
                       UNION ALL
                       SELECT id, nome, to_char(data_ini,'YYYY-MM-DD'), CASE WHEN motivo IS NOT NULL THEN 'ABONO|' || motivo WHEN tipo = 'dia' THEN 'ATESTADO_DIA' ELSE 'ATESTADO_CONS' END,
                              CASE tipo WHEN 'dia' THEN CASE WHEN data_fim = data_ini THEN 'dia inteiro' ELSE 'até ' || to_char(data_fim,'DD/MM') END ELSE to_char(hora_ini,'HH24:MI') || '–' || to_char(hora_fim,'HH24:MI') END,
                              observacao, to_char(criado_em,'YYYY-MM-DD HH24:MI'), 'atestado', 'manual', (arquivo IS NOT NULL)
                       FROM ponto_atestados WHERE status = 'pendente'
                       ORDER BY data, nome, hora");
    $l = $st->fetchAll(PDO::FETCH_ASSOC);
    $eu = $_SESSION['ponto_nome'] ?? null;
    $ex = []; // hora extra batida no Ponto, aguardando aprovação do gestor (um item por colaborador/dia)
    foreach ($pdo->query("SELECT d.id, d.nome, to_char(d.data_ref,'YYYY-MM-DD') AS data, to_char(d.criado_em,'YYYY-MM-DD HH24:MI') AS criado_em,
                                 (SELECT string_agg(h.hora_inicio || '–' || h.hora_fim, ' + ' ORDER BY h.dia_inicio, h.hora_inicio) FROM ponto_hora_extra h WHERE h.nome = d.nome AND h.data_ref = d.data_ref) AS periodos,
                                 (SELECT COALESCE(SUM(h.minutos), 0) FROM ponto_hora_extra h WHERE h.nome = d.nome AND h.data_ref = d.data_ref) AS min,
                                 (SELECT string_agg(v.vidpol, ', ' ORDER BY v.id) FROM ponto_vidpol v WHERE v.nome = d.nome AND v.data_ref = d.data_ref) AS vidpols
                          , d.justificativa AS just_he
                          FROM ponto_he_dia d WHERE d.status = 'pendente'
                            AND (EXISTS (SELECT 1 FROM ponto_vidpol v2 WHERE v2.nome = d.nome AND v2.data_ref = d.data_ref) OR COALESCE(d.justificativa,'') <> '')
                          ORDER BY d.data_ref, d.nome")->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $m = (int)$x['min'];
        $ex[] = ['id' => (int)$x['id'], 'nome' => $x['nome'], 'data' => $x['data'], 'tipo' => 'HORA_EXTRA', 'hora' => ($x['periodos'] ?? '') . ' (' . intdiv($m, 60) . 'h' . ($m % 60 ? sprintf('%02d', $m % 60) : '') . ')',
                 'justificativa' => $x['vidpols'] ? 'VIDPOL ' . $x['vidpols'] : 'Sem VIDPOL — ' . $x['just_he'], 'vidpols' => $x['vidpols'] ? explode(', ', $x['vidpols']) : [], 'criado_em' => $x['criado_em'], 'solic' => 'extra', 'origem' => 'manual', 'tem_anexo' => false,
                 'rotulo' => 'Hora extra', 'propria' => PT_BLOQUEAR_AUTOAPROVACAO && $eu !== null && $x['nome'] === $eu];
    }
    foreach ($l as &$r) { $r['id'] = (int)$r['id']; $r['rotulo'] = PT_LABEL[$r['tipo']] ?? (strncmp($r['tipo'], 'ABONO|', 6) === 0 ? substr($r['tipo'], 6) : ptRotuloAtestado($r['tipo'] === 'ATESTADO_DIA' ? 'dia' : 'consulta')); $r['justificativa'] = $r['justificativa'] ?? ''; $r['propria'] = PT_BLOQUEAR_AUTOAPROVACAO && $eu !== null && $r['nome'] === $eu; }
    unset($r);
    $ex = array_merge($ex, ptBancoFolgasItens($pdo, ['pendente'])); // pedidos de folga do banco de horas
    if ($ex) { $l = array_merge($l, $ex); usort($l, function ($a, $b) { return strcmp($a['data'] . $a['nome'], $b['data'] . $b['nome']); }); }
    resp(['sucesso' => true, 'itens' => $l]);
}
case 'ponto_admin_decidir': {
    exigeGestor(); $id = (int)($in['id'] ?? 0); $dec = $in['decisao'] ?? ''; $mot = trim((string)($in['motivo'] ?? '')); $solic = $in['solic'] ?? 'inclusao';
    if (!in_array($dec, ['aprovar', 'recusar'], true)) erro('Decisão inválida.');
    if (!in_array($solic, ['inclusao', 'remocao', 'atestado', 'extra', 'folga_banco'], true)) erro('Tipo de solicitação inválido.');
    if ($dec === 'recusar' && ptLen($mot) < 5) erro('Informe o motivo da recusa.');
    if ($solic === 'extra') { // hora extra do dia: só vai para a Escala mensal depois de aprovada
        $ap = $dec === 'aprovar';
        $q = $pdo->prepare("SELECT nome FROM ponto_he_dia WHERE id = :id"); $q->execute(['id' => $id]);
        $dono = $q->fetchColumn();
        if ($dono === false) erro('Solicitação não encontrada ou já decidida.');
        if (PT_BLOQUEAR_AUTOAPROVACAO && !empty($_SESSION['ponto_nome']) && $dono === $_SESSION['ponto_nome']) erro('Você não pode decidir a sua própria solicitação. Peça a outro gestor.');
        if ($ap) { // só se aprova a hora extra depois que o colaborador informou o VIDPOL ou a justificativa
            $c = $pdo->prepare("SELECT (SELECT count(*) FROM ponto_vidpol v WHERE v.nome = d.nome AND v.data_ref = d.data_ref) + (CASE WHEN COALESCE(d.justificativa,'') <> '' THEN 1 ELSE 0 END) FROM ponto_he_dia d WHERE d.id = :id");
            $c->execute(['id' => $id]);
            if ((int)$c->fetchColumn() === 0) erro('O colaborador ainda não informou o VIDPOL nem a justificativa desta hora extra.');
        }
        $st = $pdo->prepare("UPDATE ponto_he_dia SET status = :s, decidido_por = :u, decidido_em = now(), motivo_decisao = :m WHERE id = :id AND status = 'pendente'
                             RETURNING nome, to_char(data_ref,'YYYY-MM-DD') AS d");
        $st->execute(['s' => $ap ? 'aprovada' : 'recusada', 'u' => $_SESSION['usuario'], 'm' => $mot ?: null, 'id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) erro('Solicitação não encontrada ou já decidida.');
        $dest = $ap ? (ptBancoAtivo(ptRegra($pdo, $r['nome']), $r['d']) ? 'banco' : 'pagamento') : null; // banco de horas ligado: a hora extra vai para o banco
        $pdo->prepare("UPDATE ponto_he_dia SET destino = :x WHERE id = :id")->execute(['x' => $dest, 'id' => $id]);
        $pdo->prepare("UPDATE ponto_hora_extra SET status = :s WHERE nome = :n AND data_ref = :d")->execute(['s' => $ap ? ($dest === 'banco' ? 'banco' : 'aprovada') : 'recusada', 'n' => $r['nome'], 'd' => $r['d']]);
        evento($pdo, $id, $r['nome'], $r['d'], $ap ? 'EXTRA_APROVADA' : 'EXTRA_RECUSADA', 'HORA_EXTRA', $r['d'] . ' 00:00:00', $_SESSION['usuario'], $mot ?: null);
        auditar($pdo, $_SESSION['usuario'], 'PONTO', ($ap ? 'Aprovou' : 'Recusou') . " a hora extra de {$r['nome']} em {$r['d']}" . ($ap ? ($dest === 'banco' ? ' (enviada para o banco de horas)' : ' (liberada na Escala mensal)') : '') . ($mot ? ": $mot" : ''));
        resp(['sucesso' => true]);
    }
    if ($solic === 'folga_banco') { // folga abatendo do banco de horas
        $ap = $dec === 'aprovar';
        $q = $pdo->prepare("SELECT nome, to_char(data,'YYYY-MM-DD') AS d, minutos FROM ponto_banco_folgas WHERE id = :id AND status = 'pendente'"); $q->execute(['id' => $id]);
        $f = $q->fetch(PDO::FETCH_ASSOC);
        if (!$f) erro('Solicitação não encontrada ou já decidida.');
        if (PT_BLOQUEAR_AUTOAPROVACAO && !empty($_SESSION['ponto_nome']) && $f['nome'] === $_SESSION['ponto_nome']) erro('Você não pode decidir a sua própria solicitação. Peça a outro gestor.');
        if ($ap) {
            $b = ptBancoHoras($pdo, $f['nome']); $disp = (int)($b['disponivel_min'] ?? 0) + (int)$f['minutos'];
            if (empty($b['ativo'])) erro('O banco de horas de ' . $f['nome'] . ' não está habilitado.');
            if ($disp < max(PT_BANCO_MIN_FOLGA, (int)$f['minutos'])) erro('Saldo insuficiente no banco de horas de ' . $f['nome'] . ': disponível ' . ptHoras($disp) . '.');
        }
        $pdo->prepare("UPDATE ponto_banco_folgas SET status = :s, decidido_por = :u, decidido_em = now(), motivo_decisao = :m WHERE id = :id AND status = 'pendente'")
            ->execute(['s' => $ap ? 'aprovada' : 'recusada', 'u' => $_SESSION['usuario'], 'm' => $mot ?: null, 'id' => $id]);
        evento($pdo, $id, $f['nome'], $f['d'], $ap ? 'FOLGA_APROVADA' : 'FOLGA_RECUSADA', 'FOLGA_BANCO', $f['d'] . ' 00:00:00', $_SESSION['usuario'], $mot ?: null);
        auditar($pdo, $_SESSION['usuario'], 'PONTO', ($ap ? 'Aprovou' : 'Recusou') . " a folga do banco de horas de {$f['nome']} em {$f['d']} (" . ptHoras((int)$f['minutos']) . ')' . ($mot ? ": $mot" : ''));
        resp(['sucesso' => true]);
    }
    if ($solic === 'atestado') {
        $ap = $dec === 'aprovar';
        $st = $pdo->prepare("UPDATE ponto_atestados SET status = :s, decidido_por = :u, decidido_em = now(), motivo_decisao = :m WHERE id = :id AND status = 'pendente'
                             RETURNING nome, tipo, motivo, to_char(data_ini,'YYYY-MM-DD') AS d, to_char(data_fim,'YYYY-MM-DD') AS f");
        $st->execute(['s' => $ap ? 'aprovada' : 'recusada', 'u' => $_SESSION['usuario'], 'm' => $mot ?: null, 'id' => $id]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) erro('Solicitação não encontrada ou já decidida.');
        evento($pdo, $id, $r['nome'], $r['d'], $ap ? 'ATESTADO_APROVADO' : 'ATESTADO_RECUSADO', $r['tipo'] === 'dia' ? 'ATESTADO_DIA' : 'ATESTADO_CONS', $r['d'] . ' 00:00:00', $_SESSION['usuario'], $mot ?: null);
        auditar($pdo, $_SESSION['usuario'], 'PONTO', ($ap ? 'Aprovou' : 'Recusou') . ' ' . ($r['motivo'] ? 'abono (' . $r['motivo'] . ')' : mb_strtolower(ptRotuloAtestado($r['tipo']))) . " de {$r['nome']} ({$r['d']} a {$r['f']})" . ($mot ? ": $mot" : ''));
        resp(['sucesso' => true]);
    }
    $q = $pdo->prepare("SELECT nome FROM ponto_batidas WHERE id = :id"); $q->execute(['id' => $id]);
    $dono = $q->fetchColumn();
    if ($dono === false) erro('Solicitação não encontrada ou já decidida.');
    if (PT_BLOQUEAR_AUTOAPROVACAO && !empty($_SESSION['ponto_nome']) && $dono === $_SESSION['ponto_nome']) erro('Você não pode decidir a sua própria solicitação. Peça a outro gestor.');
    $ap = $dec === 'aprovar';
    if ($solic === 'inclusao') {
        $st = $pdo->prepare("UPDATE ponto_batidas SET status = :s, decidido_por = :u, decidido_em = now(), motivo_decisao = :m
                             WHERE id = :id AND status = 'pendente' RETURNING nome, tipo, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h");
        $st->execute(['s' => $ap ? 'aprovada' : 'recusada', 'u' => $_SESSION['usuario'], 'm' => $mot ?: null, 'id' => $id]);
    } else {
        $st = $pdo->prepare("UPDATE ponto_batidas SET remocao_status = :rs, status = CASE WHEN :ap THEN 'removida' ELSE status END,
                                    remocao_decidido_por = :u, remocao_decidido_em = now(), remocao_motivo = :m
                             WHERE id = :id AND remocao_status = 'pendente' RETURNING nome, tipo, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h");
        $st->bindValue(':rs', $ap ? 'aprovada' : 'recusada'); $st->bindValue(':ap', $ap, PDO::PARAM_BOOL);
        $st->bindValue(':u', $_SESSION['usuario']); $st->bindValue(':m', $mot ?: null); $st->bindValue(':id', $id, PDO::PARAM_INT);
        $st->execute();
    }
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) erro('Solicitação não encontrada ou já decidida.');
    evento($pdo, $id, $r['nome'], $r['d'], ($solic === 'inclusao' ? 'INCLUSAO_' : 'REMOCAO_') . ($ap ? 'APROVADA' : 'RECUSADA'), $r['tipo'], $r['d'] . ' ' . $r['h'] . ':00', $_SESSION['usuario'], $mot ?: null);
    if ($ap && $solic === 'inclusao' && in_array($r['tipo'], ['ENTRADA', 'SAIDA'], true)) ptAlmocoAuto($pdo, $r['nome'], $r['d']);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', ($ap ? 'Aprovou' : 'Recusou') . ($solic === 'inclusao' ? ' inclusão' : ' remoção') . " de batida de {$r['nome']} ({$r['tipo']} {$r['h']} em {$r['d']})" . ($mot ? ": $mot" : ''));
    resp(['sucesso' => true]);
}
case 'ponto_admin_historico': { // aprovações filtradas por colaborador, período (dia da solicitação) e status (aprovada, recusada, cancelada ou todas)
    exigeGestor();
    $ini = $_GET['inicio'] ?? date('Y-m-d', strtotime('-30 days')); $fim = $_GET['fim'] ?? date('Y-m-d', strtotime('+60 days'));
    if (!ptDataValida($ini) || !ptDataValida($fim) || $ini > $fim) erro('Período inválido.');
    if ((strtotime($fim) - strtotime($ini)) / 86400 > 366) erro('Escolha um período de até 366 dias.');
    $nomeF = '%' . trim((string)($_GET['nome'] ?? '')) . '%';
    $stF = $_GET['status'] ?? 'todas';
    $lista = in_array($stF, ['aprovada', 'recusada', 'cancelada'], true) ? "'$stF'" : "'aprovada','recusada','cancelada'";
    $par = ['i' => $ini, 'f' => $fim, 'n' => $nomeF];
    $st = $pdo->prepare("SELECT id, nome, to_char(data_ref,'YYYY-MM-DD') AS data, tipo, to_char(momento,'HH24:MI') AS hora, justificativa,
                              to_char(criado_em,'YYYY-MM-DD HH24:MI') AS criado_em, 'inclusao' AS solic, origem, false AS tem_anexo, status,
                              decidido_por, to_char(decidido_em,'YYYY-MM-DD HH24:MI') AS decidido_em, motivo_decisao
                       FROM ponto_batidas WHERE origem <> 'facial' AND status IN ($lista) AND data_ref BETWEEN :i AND :f AND nome ILIKE :n
                       UNION ALL
                       SELECT id, nome, to_char(data_ref,'YYYY-MM-DD'), tipo, to_char(momento,'HH24:MI'), remocao_justificativa,
                              to_char(remocao_em,'YYYY-MM-DD HH24:MI'), 'remocao', origem, false, remocao_status,
                              remocao_decidido_por, to_char(remocao_decidido_em,'YYYY-MM-DD HH24:MI'), remocao_motivo
                       FROM ponto_batidas WHERE remocao_status IN ($lista) AND data_ref BETWEEN :i AND :f AND nome ILIKE :n
                       UNION ALL
                       SELECT id, nome, to_char(data_ini,'YYYY-MM-DD'), CASE WHEN motivo IS NOT NULL THEN 'ABONO|' || motivo WHEN tipo = 'dia' THEN 'ATESTADO_DIA' ELSE 'ATESTADO_CONS' END,
                              CASE tipo WHEN 'dia' THEN CASE WHEN data_fim = data_ini THEN 'dia inteiro' ELSE 'até ' || to_char(data_fim,'DD/MM') END ELSE to_char(hora_ini,'HH24:MI') || '–' || to_char(hora_fim,'HH24:MI') END,
                              observacao, to_char(criado_em,'YYYY-MM-DD HH24:MI'), 'atestado', 'manual', (arquivo IS NOT NULL), status,
                              decidido_por, to_char(decidido_em,'YYYY-MM-DD HH24:MI'), motivo_decisao
                       FROM ponto_atestados WHERE status IN ($lista) AND data_ini <= :f AND data_fim >= :i AND nome ILIKE :n
                       ORDER BY data DESC, nome");
    $st->execute($par);
    $l = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($l as &$r) {
        $r['id'] = (int)$r['id'];
        $r['rotulo'] = PT_LABEL[$r['tipo']] ?? (strncmp($r['tipo'], 'ABONO|', 6) === 0 ? substr($r['tipo'], 6) : ptRotuloAtestado($r['tipo'] === 'ATESTADO_DIA' ? 'dia' : 'consulta'));
        $r['justificativa'] = $r['justificativa'] ?? ''; $r['propria'] = false; $r['cancelavel'] = $r['status'] === 'aprovada';
    }
    unset($r);
    $q = $pdo->prepare("SELECT d.id, d.nome, to_char(d.data_ref,'YYYY-MM-DD') AS data, to_char(d.criado_em,'YYYY-MM-DD HH24:MI') AS criado_em, d.status, d.decidido_por,
                               to_char(d.decidido_em,'YYYY-MM-DD HH24:MI') AS decidido_em, d.motivo_decisao,
                               (SELECT string_agg(h.hora_inicio || '–' || h.hora_fim, ' + ' ORDER BY h.dia_inicio, h.hora_inicio) FROM ponto_hora_extra h WHERE h.nome = d.nome AND h.data_ref = d.data_ref) AS periodos,
                               (SELECT COALESCE(SUM(h.minutos), 0) FROM ponto_hora_extra h WHERE h.nome = d.nome AND h.data_ref = d.data_ref) AS min,
                               (SELECT string_agg(v.vidpol, ', ' ORDER BY v.id) FROM ponto_vidpol v WHERE v.nome = d.nome AND v.data_ref = d.data_ref) AS vidpols
                        FROM ponto_he_dia d WHERE d.status IN ($lista) AND d.data_ref BETWEEN :i AND :f AND d.nome ILIKE :n ORDER BY d.data_ref DESC, d.nome");
    $q->execute($par);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $x) {
        $m = (int)$x['min'];
        $l[] = ['id' => (int)$x['id'], 'nome' => $x['nome'], 'data' => $x['data'], 'tipo' => 'HORA_EXTRA', 'hora' => $m > 0 ? ($x['periodos'] ?? '') . ' (' . intdiv($m, 60) . 'h' . ($m % 60 ? sprintf('%02d', $m % 60) : '') . ')' : 'removida',
                'justificativa' => $x['vidpols'] ? 'VIDPOL ' . $x['vidpols'] : ($x['status'] === 'cancelada' ? '—' : 'Sem VIDPOL informado'), 'vidpols' => $x['vidpols'] ? explode(', ', $x['vidpols']) : [], 'criado_em' => $x['criado_em'],
                'solic' => 'extra', 'origem' => 'manual', 'tem_anexo' => false, 'rotulo' => 'Hora extra', 'status' => $x['status'], 'decidido_por' => $x['decidido_por'],
                'decidido_em' => $x['decidido_em'], 'motivo_decisao' => $x['motivo_decisao'], 'propria' => false, 'cancelavel' => $x['status'] === 'aprovada'];
    }
    $l = array_merge($l, ptBancoFolgasItens($pdo, in_array($stF, ['aprovada', 'recusada', 'cancelada'], true) ? [$stF] : ['aprovada', 'recusada', 'cancelada'], $ini, $fim, $nomeF));
    usort($l, function ($a, $b) { return strcmp($b['data'] . $b['nome'], $a['data'] . $a['nome']); });
    resp(['sucesso' => true, 'itens' => $l]);
}
case 'ponto_admin_cancelar': { // o gestor cancela uma aprovação: os efeitos são desfeitos e a solicitação fica "cancelada"
    exigeGestor(); $id = (int)($in['id'] ?? 0); $mot = trim((string)($in['motivo'] ?? '')); $solic = $in['solic'] ?? '';
    if (!in_array($solic, ['inclusao', 'remocao', 'atestado', 'extra', 'folga_banco'], true)) erro('Tipo de solicitação inválido.');
    if (ptLen($mot) < 5) erro('Informe o motivo do cancelamento.');
    $eu = $_SESSION['usuario']; $texto = 'Cancelada: ' . $mot;
    if ($solic === 'folga_banco') { // cancelar a aprovação da folga devolve as horas ao banco
        $st = $pdo->prepare("UPDATE ponto_banco_folgas SET status = 'cancelada', decidido_por = :u, decidido_em = now(), motivo_decisao = :m WHERE id = :id AND status = 'aprovada'
                             RETURNING nome, to_char(data,'YYYY-MM-DD') AS d, minutos");
        $st->execute(['u' => $eu, 'm' => $texto, 'id' => $id]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) erro('Aprovação não encontrada ou já alterada.');
        evento($pdo, $id, $r['nome'], $r['d'], 'FOLGA_APROVACAO_CANCELADA', 'FOLGA_BANCO', $r['d'] . ' 00:00:00', $eu, $mot);
        auditar($pdo, $eu, 'PONTO', "Cancelou a folga do banco de horas de {$r['nome']} em {$r['d']}: $mot");
        resp(['sucesso' => true]);
    }
    if ($solic === 'extra') {
        $q = $pdo->prepare("SELECT nome FROM ponto_he_dia WHERE id = :id AND status = 'aprovada'"); $q->execute(['id' => $id]);
        $dono = $q->fetchColumn(); if ($dono === false) erro('Aprovação não encontrada ou já alterada.');
        if (PT_BLOQUEAR_AUTOAPROVACAO && !empty($_SESSION['ponto_nome']) && $dono === $_SESSION['ponto_nome']) erro('Você não pode cancelar a sua própria solicitação. Peça a outro gestor.');
        $st = $pdo->prepare("UPDATE ponto_he_dia SET status = 'cancelada', decidido_por = :u, decidido_em = now(), motivo_decisao = :m WHERE id = :id AND status = 'aprovada'
                             RETURNING nome, to_char(data_ref,'YYYY-MM-DD') AS d");
        $st->execute(['u' => $eu, 'm' => $texto, 'id' => $id]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) erro('Aprovação não encontrada ou já alterada.');
        // as batidas que geraram a hora extra saem da folha (status 'removida') e os VIDPOL do dia são excluídos; o colaborador fica com a batida pendente
        $orig = [];
        foreach (ptPeriodosExtra($pdo, ptRegra($pdo, $r['nome']), $r['nome'], $r['d']) as $pe) $orig[$pe[2]] = true;
        if (!$orig) { $o = $pdo->prepare("SELECT DISTINCT origem FROM ponto_hora_extra WHERE nome = :n AND data_ref = :d"); $o->execute(['n' => $r['nome'], 'd' => $r['d']]); foreach ($o->fetchAll(PDO::FETCH_COLUMN) as $x) $orig[$x] = true; }
        $tp = [];
        if (isset($orig['extra'])) $tp = array_merge($tp, ['EXTRA_ENTRADA', 'EXTRA_SAIDA']);
        if (isset($orig['excedente'])) $tp[] = 'SAIDA';
        if (isset($orig['sem_expediente'])) $tp = array_merge($tp, ['ENTRADA', 'SAIDA_ALMOCO', 'RETORNO_ALMOCO', 'SAIDA']);
        $tp = array_values(array_unique($tp)); $remov = [];
        if ($tp) {
            $lst = "'" . implode("','", $tp) . "'"; // só constantes fixas acima
            $rb = $pdo->prepare("UPDATE ponto_batidas SET status = 'removida' WHERE nome = :n AND data_ref = :d AND tipo IN ($lst) AND status IN ('valida','aprovada')
                                 RETURNING id, tipo, to_char(momento,'HH24:MI') AS h, to_char(momento,'YYYY-MM-DD HH24:MI:SS') AS mom");
            $rb->execute(['n' => $r['nome'], 'd' => $r['d']]);
            foreach ($rb->fetchAll(PDO::FETCH_ASSOC) as $bx) {
                $remov[] = (PT_LABEL[$bx['tipo']] ?? $bx['tipo']) . ' ' . $bx['h'];
                evento($pdo, (int)$bx['id'], $r['nome'], $r['d'], 'HE_CANCELADA_BATIDA', $bx['tipo'], $bx['mom'], $eu, 'Batida removida porque a hora extra foi cancelada: ' . $mot);
            }
        }
        $vp = $pdo->prepare("DELETE FROM ponto_vidpol WHERE nome = :n AND data_ref = :d RETURNING vidpol"); $vp->execute(['n' => $r['nome'], 'd' => $r['d']]);
        $vpl = $vp->fetchAll(PDO::FETCH_COLUMN);
        if ($vpl) evento($pdo, null, $r['nome'], $r['d'], 'VIDPOL_REMOVIDO', null, null, $eu, implode(', ', $vpl) . ' (hora extra cancelada)');
        $pdo->prepare("DELETE FROM ponto_hora_extra WHERE nome = :n AND data_ref = :d")->execute(['n' => $r['nome'], 'd' => $r['d']]);
        if ($remov || $vpl) $pdo->prepare("UPDATE ponto_he_dia SET motivo_decisao = motivo_decisao || :x WHERE id = :id")->execute(['x' => ' [removido: ' . implode(', ', array_merge($remov, $vpl ? ['VIDPOL ' . implode(' ', $vpl)] : [])) . ']', 'id' => $id]);
        if ($remov || $vpl) auditar($pdo, $eu, 'PONTO', "Hora extra cancelada de {$r['nome']} em {$r['d']}: removeu " . ($remov ? 'a(s) batida(s) ' . implode(', ', $remov) : 'nenhuma batida') . ($vpl ? ' e o(s) VIDPOL ' . implode(', ', $vpl) : ''));
        evento($pdo, $id, $r['nome'], $r['d'], 'EXTRA_APROVACAO_CANCELADA', 'HORA_EXTRA', $r['d'] . ' 00:00:00', $eu, $mot);
        auditar($pdo, $eu, 'PONTO', "Cancelou a aprovação da hora extra de {$r['nome']} em {$r['d']} (removida da Escala mensal): $mot");
        resp(['sucesso' => true]);
    }
    if ($solic === 'atestado') {
        $q = $pdo->prepare("SELECT nome FROM ponto_atestados WHERE id = :id AND status = 'aprovada'"); $q->execute(['id' => $id]);
        $dono = $q->fetchColumn(); if ($dono === false) erro('Aprovação não encontrada ou já alterada.');
        if (PT_BLOQUEAR_AUTOAPROVACAO && !empty($_SESSION['ponto_nome']) && $dono === $_SESSION['ponto_nome']) erro('Você não pode cancelar a sua própria solicitação. Peça a outro gestor.');
        $st = $pdo->prepare("UPDATE ponto_atestados SET status = 'cancelada', decidido_por = :u, decidido_em = now(), motivo_decisao = :m WHERE id = :id AND status = 'aprovada'
                             RETURNING nome, tipo, motivo, to_char(data_ini,'YYYY-MM-DD') AS d, to_char(data_fim,'YYYY-MM-DD') AS f");
        $st->execute(['u' => $eu, 'm' => $texto, 'id' => $id]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) erro('Aprovação não encontrada ou já alterada.');
        evento($pdo, $id, $r['nome'], $r['d'], 'ATESTADO_APROVACAO_CANCELADA', $r['tipo'] === 'dia' ? 'ATESTADO_DIA' : 'ATESTADO_CONS', $r['d'] . ' 00:00:00', $eu, $mot);
        auditar($pdo, $eu, 'PONTO', 'Cancelou a aprovação de ' . ($r['motivo'] ? 'abono (' . $r['motivo'] . ')' : mb_strtolower(ptRotuloAtestado($r['tipo']))) . " de {$r['nome']} ({$r['d']} a {$r['f']}): $mot");
        resp(['sucesso' => true]);
    }
    $q = $pdo->prepare("SELECT nome FROM ponto_batidas WHERE id = :id"); $q->execute(['id' => $id]);
    $dono = $q->fetchColumn(); if ($dono === false) erro('Aprovação não encontrada ou já alterada.');
    if (PT_BLOQUEAR_AUTOAPROVACAO && !empty($_SESSION['ponto_nome']) && $dono === $_SESSION['ponto_nome']) erro('Você não pode cancelar a sua própria solicitação. Peça a outro gestor.');
    if ($solic === 'inclusao') { // a batida incluída deixa de valer
        $st = $pdo->prepare("UPDATE ponto_batidas SET status = 'cancelada', decidido_por = :u, decidido_em = now(), motivo_decisao = :m
                             WHERE id = :id AND status = 'aprovada' AND origem <> 'facial' RETURNING nome, tipo, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h");
        $st->execute(['u' => $eu, 'm' => $texto, 'id' => $id]);
    } else { // a remoção é desfeita: a batida volta a valer
        $st = $pdo->prepare("UPDATE ponto_batidas SET status = CASE WHEN decidido_em IS NOT NULL THEN 'aprovada' ELSE 'valida' END, remocao_status = 'cancelada',
                                    remocao_decidido_por = :u, remocao_decidido_em = now(), remocao_motivo = :m
                             WHERE id = :id AND remocao_status = 'aprovada' AND status = 'removida' RETURNING nome, tipo, to_char(data_ref,'YYYY-MM-DD') AS d, to_char(momento,'HH24:MI') AS h");
        $st->execute(['u' => $eu, 'm' => $texto, 'id' => $id]);
    }
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) erro('Aprovação não encontrada ou já alterada.');
    try { ptSincronizarHE($pdo, ptRegra($pdo, $r['nome']), $r['nome'], $r['d']); } catch (Exception $e) {}
    evento($pdo, $id, $r['nome'], $r['d'], ($solic === 'inclusao' ? 'INCLUSAO' : 'REMOCAO') . '_APROVACAO_CANCELADA', $r['tipo'], $r['d'] . ' ' . $r['h'] . ':00', $eu, $mot);
    auditar($pdo, $eu, 'PONTO', 'Cancelou a aprovação de ' . ($solic === 'inclusao' ? 'inclusão' : 'remoção') . " de batida de {$r['nome']} ({$r['tipo']} {$r['h']} em {$r['d']}): $mot");
    resp(['sucesso' => true]);
}
case 'ponto_admin_registros': {
    exigeGestor();
    $ini = $_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days')); $fim = $_GET['fim'] ?? date('Y-m-d');
    if (!ptDataValida($ini) || !ptDataValida($fim) || $ini > $fim) erro('Período inválido.');
    if ((strtotime($fim) - strtotime($ini)) / 86400 > 62) erro('Escolha um período de até 62 dias.');
    $filtro = trim($_GET['nome'] ?? '');
    $st = $pdo->prepare("SELECT DISTINCT nome FROM ponto_colaboradores WHERE ativo AND (cadastrado_em IS NOT NULL) AND (:f = '' OR nome ILIKE :fl) ORDER BY nome");
    $st->execute(['f' => $filtro, 'fl' => '%' . $filtro . '%']);
    $nomes = $st->fetchAll(PDO::FETCH_COLUMN); $out = [];
    foreach ($nomes as $n) {
        $regraN = ptRegra($pdo, $n); $ad = ptAdmissao($pdo, $n);
        for ($d = $fim; $d >= $ini; $d = date('Y-m-d', strtotime("$d -1 day"))) {
            if ($ad && $d < $ad) continue; // só a partir da admissão
            $s = ptStatusDia($pdo, $regraN, $n, $d);
            if ($s['batidas'] || $s['alerta']) { $s['nome'] = $n; $out[] = $s; }
        }
    }
    resp(['sucesso' => true, 'dias' => $out, 'rotulos' => PT_LABEL]);
}
case 'ponto_admin_dashboard': { // painel do gestor (parte leve): situação de hoje, aprovações pendentes, movimentações e cadastro
    exigeGestor();
    $agora = time(); $hoje = date('Y-m-d', $agora);
    $cols = $pdo->query("SELECT f.nome, f.setor, (p.descritor IS NOT NULL) AS cadastrado, COALESCE(p.ativo, TRUE) AS ativo
                         FROM funcionarios f LEFT JOIN ponto_colaboradores p ON p.nome = f.nome ORDER BY f.nome")->fetchAll(PDO::FETCH_ASSOC);
    $k = ['monitorados' => 0, 'esperados' => 0, 'trabalhando' => 0, 'completos' => 0, 'em_andamento' => 0, 'atrasados' => 0, 'aguardando' => 0,
          'sem_batida' => 0, 'fora_expediente' => 0, 'extra_hoje' => 0, 'batidas_hoje' => 0];
    $pend = []; $semRosto = []; $desat = 0;
    foreach ($cols as $c) {
        if (!$c['ativo']) { $desat++; continue; }
        if (!$c['cadastrado']) { $semRosto[] = $c['nome']; continue; }
        $k['monitorados']++;
        $s = ptStatusDia($pdo, ptRegra($pdo, $c['nome']), $c['nome'], $hoje, $agora);
        $at = ptAtivas($s['batidas']); $k['batidas_hoje'] += count($at);
        $ult = $at ? $at[count($at) - 1]['tipo'] : null;
        if (in_array($ult, ['ENTRADA', 'RETORNO_ALMOCO', 'EXTRA_ENTRADA'], true)) $k['trabalhando']++;
        if (!($s['tem_regra'] && !$s['sem_obrigacao'])) { if ($at) $k['extra_hoje']++; else $k['fora_expediente']++; continue; }
        $k['esperados']++;
        if (!$s['faltam']) $k['completos']++; elseif ($s['alerta']) $k['atrasados']++; elseif (!$at) $k['aguardando']++; else $k['em_andamento']++;
        if (!$at) $k['sem_batida']++;
        if ($s['alerta'] && $s['faltam']) {
            $pend[] = ['nome' => $c['nome'], 'setor' => $c['setor'], 'alerta' => $s['alerta'], 'sem_batida' => !$at,
                       'feitas' => array_map(function ($b) { return ['tipo' => $b['tipo'], 'hora' => substr($b['momento'], 11, 5)]; }, $at),
                       'faltam' => array_values($s['faltam'])];
        }
    }
    usort($pend, function ($a, $b) { return [count($a['feitas']), $a['nome']] <=> [count($b['feitas']), $b['nome']]; });
    $apv = ['inclusao' => 0, 'remocao' => 0, 'atestado' => 0, 'mais_antiga_min' => 0]; $porC = [];
    $q = $pdo->query("SELECT solic, nome, EXTRACT(EPOCH FROM (now() - em)) / 60 AS min FROM (
                        SELECT 'inclusao' AS solic, nome, criado_em AS em FROM ponto_batidas WHERE status = 'pendente'
                        UNION ALL SELECT 'remocao', nome, remocao_em FROM ponto_batidas WHERE remocao_status = 'pendente'
                        UNION ALL SELECT 'atestado', nome, criado_em FROM ponto_atestados WHERE status = 'pendente') x");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $apv[$r['solic']]++; $porC[$r['nome']] = ($porC[$r['nome']] ?? 0) + 1;
        $apv['mais_antiga_min'] = max($apv['mais_antiga_min'], (int)$r['min']);
    }
    arsort($porC);
    $apv['total'] = $apv['inclusao'] + $apv['remocao'] + $apv['atestado']; $apv['colaboradores'] = count($porC);
    $apv['por_colab'] = []; foreach (array_slice($porC, 0, 8, true) as $n => $q2) $apv['por_colab'][] = ['nome' => $n, 'n' => $q2];
    $mv = $pdo->query("SELECT evento, nome, tipo, usuario, to_char(momento,'HH24:MI') AS hora, to_char(data_ref,'DD/MM') AS dia,
                              to_char(criado_em,'YYYY-MM-DD HH24:MI:SS') AS criado_em FROM ponto_eventos ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);
    resp(['sucesso' => true, 'agora' => date('Y-m-d H:i:s', $agora), 'hoje' => $hoje, 'kpi' => $k, 'pendentes_batida' => $pend, 'aprovacoes' => $apv, 'movimentos' => $mv,
          'cadastro' => ['total' => count($cols), 'sem_rosto' => count($semRosto), 'nomes_sem_rosto' => array_slice($semRosto, 0, 10), 'desativados' => $desat],
          'rotulos' => PT_LABEL]);
}
case 'ponto_admin_dashboard_folha': { // painel do gestor (parte da folha): totais, séries diárias, setores, pontualidade, origem das batidas e fluxo de solicitações
    exigeGestor();
    $atual = ptFolhaRefDe(date('Y-m-d')); $ref = $_GET['ref'] ?? $atual; $hoje = date('Y-m-d');
    if (!ptFolhaRefValida($ref)) erro('Folha inválida.');
    if ($ref > $atual) erro('Esta folha ainda não começou.');
    $cols = $pdo->query("SELECT f.nome, f.setor, to_char(GREATEST(p.cadastrado_em::date, f.data_admissao),'YYYY-MM-DD') AS desde
                         FROM funcionarios f JOIN ponto_colaboradores p ON p.nome = f.nome
                         WHERE p.descritor IS NOT NULL AND p.ativo ORDER BY f.nome")->fetchAll(PDO::FETCH_ASSOC);
    $tot = ['colaboradores' => count($cols), 'extra_min' => 0, 'pagar_min' => 0, 'trabalhado_min' => 0, 'dias_ajustar' => 0, 'colab_ajustar' => 0,
            'colab_extra' => 0, 'vidpol' => 0, 'extras_sem_vidpol' => 0, 'solicitacoes' => 0,
            'esperados' => 0, 'completos' => 0, 'entradas' => 0, 'pontuais' => 0, 'atraso_total' => 0, 'atrasados' => 0, 'facial' => 0, 'manual' => 0];
    $lin = []; $per = null; $serie = []; $setores = []; $bk = [0, 0, 0, 0, 0]; // atraso na entrada: pontual / 1-15 / 16-30 / 31-60 / mais de 60 min
    foreach ($cols as $c) {
        $regra = ptRegra($pdo, $c['nome']);
        $f = ptFolha($pdo, $regra, $c['nome'], $ref, null, $c['desde'] ?: null);
        $per = [$f['inicio'], $f['fim']]; $r = $f['resumo']; $semV = 0; $nv = 0; $nAtr = 0; $nEnt = 0;
        $flex = max(0, min(PT_FLEX_MAX, (int)($regra['flex_min'] ?? 0))); $tol = (int)($regra['tolerancia'] ?? 0);
        foreach ($f['dias'] as $d) {
            $dt = $d['data'];
            if (!isset($serie[$dt])) $serie[$dt] = ['data' => $dt, 'dow' => $d['dow'], 'completos' => 0, 'incompletos' => 0, 'sem_batida' => 0, 'esperados' => 0, 'extra_min' => 0];
            $nv += count($d['vidpols']);
            if (($d['extra_min'] ?? 0) > 0 && !$d['vidpols'] && empty($d['he_just'])) $semV++;
            if ($d['futuro']) continue;
            if ($c['desde'] && $dt < $c['desde']) continue;
            $at = ptAtivas($d['batidas']);
            $serie[$dt]['extra_min'] += (($d['he_status'] ?? '') === 'aprovada') ? (int)($d['extra_min'] ?? 0) : 0; // só a hora extra aprovada é computada
            foreach ($at as $b) { if ($b['origem'] === 'facial') $tot['facial']++; else $tot['manual']++; }
            $esp = $d['tem_regra'] && !$d['sem_obrigacao'];
            if (!$esp) continue;
            foreach ($at as $b) if ($b['tipo'] === 'ENTRADA') { // pontualidade da entrada: tolerância + flexibilidade da regra do colaborador
                $prog = ptMin($regra['dias'][(string)$d['dow']]['ini']); $atraso = max(0, ptMinDoMomento($b['momento']) - $prog - $tol - $flex);
                $tot['entradas']++; $nEnt++;
                if ($atraso === 0) { $tot['pontuais']++; $bk[0]++; } else { $tot['atrasados']++; $tot['atraso_total'] += $atraso; $nAtr++; $bk[$atraso <= 15 ? 1 : ($atraso <= 30 ? 2 : ($atraso <= 60 ? 3 : 4))]++; }
            }
            if ($dt < $hoje) { // presença só nos dias já fechados
                $serie[$dt]['esperados']++; $tot['esperados']++;
                if (!$at) $serie[$dt]['sem_batida']++; elseif ($d['trabalhado_min'] !== null) { $serie[$dt]['completos']++; $tot['completos']++; } else $serie[$dt]['incompletos']++;
            }
        }
        $lin[] = ['nome' => $c['nome'], 'setor' => $c['setor'], 'extra_min' => (int)$r['extra_min'], 'pagar_min' => (int)$r['pagar_min'], 'trabalhado_min' => (int)$r['trabalhado_min'],
                  'dias_ajustar' => (int)$r['dias_pendentes'], 'solicitacoes' => (int)$r['solicitacoes'], 'vidpol' => $nv, 'extras_sem_vidpol' => $semV, 'atrasos' => $nAtr, 'entradas' => $nEnt];
        $st = $c['setor'] ?: 'Sem setor';
        if (!isset($setores[$st])) $setores[$st] = ['setor' => $st, 'colaboradores' => 0, 'extra_min' => 0, 'trabalhado_min' => 0, 'dias_ajustar' => 0];
        $setores[$st]['colaboradores']++; $setores[$st]['extra_min'] += $r['extra_min']; $setores[$st]['trabalhado_min'] += $r['trabalhado_min']; $setores[$st]['dias_ajustar'] += $r['dias_pendentes'];
        $tot['extra_min'] += $r['extra_min']; $tot['pagar_min'] += $r['pagar_min']; $tot['trabalhado_min'] += $r['trabalhado_min'];
        $tot['dias_ajustar'] += $r['dias_pendentes']; $tot['solicitacoes'] += $r['solicitacoes']; $tot['vidpol'] += $nv; $tot['extras_sem_vidpol'] += $semV;
        if ($r['dias_pendentes'] > 0) $tot['colab_ajustar']++;
        if ($r['extra_min'] > 0) $tot['colab_extra']++;
    }
    $ex = array_values(array_filter($lin, function ($x) { return $x['extra_min'] > 0; }));
    usort($ex, function ($a, $b) { return [$b['extra_min'], $a['nome']] <=> [$a['extra_min'], $b['nome']]; });
    $aj = array_values(array_filter($lin, function ($x) { return $x['dias_ajustar'] > 0; }));
    usort($aj, function ($a, $b) { return [$b['dias_ajustar'], $a['nome']] <=> [$a['dias_ajustar'], $b['nome']]; });
    $at2 = array_values(array_filter($lin, function ($x) { return $x['atrasos'] > 0; }));
    usort($at2, function ($a, $b) { return [$b['atrasos'], $a['nome']] <=> [$a['atrasos'], $b['nome']]; });
    $sx = array_values(array_filter($setores, function ($x) { return $x['extra_min'] > 0; }));
    usort($sx, function ($a, $b) { return [$b['extra_min'], $a['setor']] <=> [$a['extra_min'], $b['setor']]; });
    if (!$per) $per = ptFolhaPeriodo($ref);
    ksort($serie);
    // fluxo de solicitações (últimas 8 semanas) e tempo de decisão (últimos 30 dias) — não dependem da folha escolhida
    $semanas = [];
    for ($i = 7; $i >= 0; $i--) { $m = date('Y-m-d', strtotime('monday this week -' . $i . ' weeks')); $semanas[$m] = ['semana' => $m, 'inclusao' => 0, 'remocao' => 0]; }
    $q = $pdo->query("SELECT to_char(date_trunc('week', criado_em),'YYYY-MM-DD') AS s, evento, count(*) AS n FROM ponto_eventos
                      WHERE evento IN ('INCLUSAO_SOLICITADA','REMOCAO_SOLICITADA') AND criado_em >= date_trunc('week', now()) - interval '7 weeks' GROUP BY 1, 2");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) if (isset($semanas[$r['s']])) $semanas[$r['s']][$r['evento'] === 'INCLUSAO_SOLICITADA' ? 'inclusao' : 'remocao'] = (int)$r['n'];
    $q = $pdo->query("SELECT res, count(*) AS n, avg(seg) / 60 AS min FROM (
                        SELECT status AS res, EXTRACT(EPOCH FROM (decidido_em - criado_em)) AS seg FROM ponto_batidas WHERE status IN ('aprovada','recusada') AND origem <> 'facial' AND decidido_em >= now() - interval '30 days'
                        UNION ALL SELECT remocao_status, EXTRACT(EPOCH FROM (remocao_decidido_em - remocao_em)) FROM ponto_batidas WHERE remocao_status IN ('aprovada','recusada') AND remocao_decidido_em >= now() - interval '30 days') x
                      WHERE seg >= 0 GROUP BY res");
    $dec = ['aprovadas' => 0, 'recusadas' => 0, 'tempo_medio_min' => null]; $som = 0; $nn = 0;
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $dec[$r['res'] === 'aprovada' ? 'aprovadas' : 'recusadas'] = (int)$r['n']; $som += $r['min'] * $r['n']; $nn += $r['n']; }
    if ($nn) $dec['tempo_medio_min'] = (int)round($som / $nn);
    resp(['sucesso' => true, 'ref' => $ref, 'inicio' => $per[0], 'fim' => $per[1], 'anterior' => ptFolhaDeslocar($ref, -1), 'proxima' => ptFolhaDeslocar($ref, 1), 'atual' => $atual, 'hoje' => $hoje,
          'totais' => $tot, 'ranking_extra' => array_slice($ex, 0, 10), 'ranking_ajustar' => array_slice($aj, 0, 10), 'ranking_atrasos' => array_slice($at2, 0, 8),
          'serie' => array_values($serie), 'setores' => array_slice($sx, 0, 10), 'atraso_faixas' => $bk, 'solic_semanas' => array_values($semanas), 'decisoes' => $dec]);
}
case 'ponto_admin_foto': {
    exigeGestor();
    $st = $pdo->prepare("SELECT foto FROM ponto_batidas WHERE id = :id"); $st->execute(['id' => (int)($_GET['id'] ?? 0)]);
    $f = $st->fetchColumn();
    if (!$f) erro('Sem foto.');
    resp(['sucesso' => true, 'foto' => $f]);
}
case 'ponto_admin_opcoes': { // opções da aba Regra de horário
    exigeGestor();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $map = ['gestor_edita' => 'ponto_gestor_edita', 'email_auto' => 'ponto_email_auto'];
        foreach ($map as $k => $chave) if (array_key_exists($k, $in)) {
            ptOpcaoSalvar($pdo, $chave, !empty($in[$k]));
            auditar($pdo, $_SESSION['usuario'], 'PONTO', ($k === 'gestor_edita' ? 'Gestor pode incluir/remover batidas dos subordinados' : 'Relatório automático da folha por e-mail') . ': ' . (!empty($in[$k]) ? 'habilitado' : 'desabilitado'));
        }
    }
    resp(['sucesso' => true, 'gestor_edita' => ptGestorEdita($pdo), 'email_auto' => ptEmailAuto($pdo), 'smtp' => smtpConfigurado(smtpConfigLer($pdo))]);
}
case 'ponto_admin_email': { // e-mail do colaborador (destino dos avisos e do relatório da folha)
    exigeGestor(); $nome = trim($in['nome'] ?? ''); $em = trim((string)($in['email'] ?? ''));
    $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
    if (!$st->fetchColumn()) erro('Colaborador não encontrado.');
    if ($em !== '' && (strlen($em) > 200 || !filter_var($em, FILTER_VALIDATE_EMAIL))) erro('E-mail inválido.');
    $pdo->prepare("INSERT INTO ponto_colaboradores (nome, email) VALUES (:n, :e) ON CONFLICT (nome) DO UPDATE SET email = EXCLUDED.email")->execute(['n' => $nome, 'e' => $em === '' ? null : $em]);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', ($em === '' ? "Removeu o e-mail de $nome" : "Definiu o e-mail de $nome ($em)"));
    resp(['sucesso' => true]);
}
case 'ponto_admin_notificar': { // Apuração: avisa o colaborador, por e-mail, das datas que precisam ser regularizadas
    exigeGestor(); $nome = trim($in['nome'] ?? ''); $ref = $in['ref'] ?? ptFolhaRefDe(date('Y-m-d'));
    $st = $pdo->prepare("SELECT 1 FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
    if (!$st->fetchColumn()) erro('Colaborador não encontrado.');
    if (!ptFolhaRefValida($ref) || $ref > ptFolhaRefDe(date('Y-m-d'))) erro('Folha inválida.');
    $datas = ptDatasRegularizar($pdo, ptRegra($pdo, $nome), $nome, $ref);
    $email = ptEmailDoColab($pdo, $nome);
    if (!empty($in['previa'])) resp(['sucesso' => true, 'email' => $email, 'datas' => array_map(function ($d) { return ptBrData($d['data']); }, $datas), 'smtp' => smtpConfigurado(smtpConfigLer($pdo))]);
    if (!$datas) resp(['sucesso' => true, 'enviado' => false, 'datas' => 0]);
    $r = ptEnviarEmailFolha($pdo, $nome, $ref, 'manual', $_SESSION['nome'] ?? $_SESSION['usuario']);
    if (!$r['ok']) erro($r['erro']);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', "Notificou $nome por e-mail para regularizar " . $r['datas'] . ' data(s) da folha ' . $ref);
    resp(['sucesso' => true, 'enviado' => $r['enviado'], 'datas' => $r['datas'], 'email' => $email]);
}
case 'ponto_admin_exportar': { // aba Registros: CSV ou relatório imprimível (PDF) com a foto de cada batida facial
    exigeGestor();
    $ini = $_GET['inicio'] ?? date('Y-m-d', strtotime('-7 days')); $fim = $_GET['fim'] ?? date('Y-m-d');
    if (!ptDataValida($ini) || !ptDataValida($fim) || $ini > $fim) erro('Período inválido.');
    if ((strtotime($fim) - strtotime($ini)) / 86400 > 62) erro('Escolha um período de até 62 dias.');
    $filtro = trim($_GET['nome'] ?? ''); $fmt = $_GET['formato'] ?? ''; $html = $fmt === 'html'; $xlsx = $fmt === 'xlsx'; $pdfF = $fmt === 'pdf'; $comFotos = ($_GET['fotos'] ?? '') === '1';
    @set_time_limit(120);
    $dias = ptRegistrosPeriodo($pdo, $ini, $fim, $filtro);
    auditar($pdo, $_SESSION['usuario'], 'PONTO', 'Exportou os registros do ponto (' . (($html || $pdfF) ? ($comFotos ? 'PDF com fotos' : 'PDF sem fotos') : ($xlsx ? 'XLSX' : 'CSV')) . ") de $ini a $fim" . ($filtro !== '' ? " — $filtro" : ''));
    header_remove('Content-Type');
    if ($html) { header('Content-Type: text/html; charset=utf-8'); echo ptExportarHtml($pdo, $dias, $ini, $fim, $filtro, $_SESSION['nome'] ?? $_SESSION['usuario'], 500, $comFotos, ($_GET['imprimir'] ?? '') === '1'); exit; }
    if ($pdfF) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="registros-ponto-' . $ini . '_a_' . $fim . ($comFotos ? '_com-fotos' : '') . '.pdf"');
        echo ptExportarPdf($pdo, $dias, $ini, $fim, $filtro, $_SESSION['nome'] ?? $_SESSION['usuario'], $comFotos); exit;
    }
    if ($xlsx) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="registros-ponto-' . $ini . '_a_' . $fim . '.xlsx"');
        echo ptExportarXlsx($dias, ptUrl()); exit;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="registros-ponto-' . $ini . '_a_' . $fim . '.csv"');
    echo ptExportarCsv($dias, ptUrl()); exit;
}
case 'ponto_admin_foto_img': { // a foto da batida como imagem (link do CSV; só gestor logado)
    exigeGestor();
    $st = $pdo->prepare("SELECT foto FROM ponto_batidas WHERE id = :id"); $st->execute(['id' => (int)($_GET['id'] ?? 0)]);
    $f = (string)$st->fetchColumn();
    if (!preg_match('#^data:image/jpeg;base64,(.+)$#s', $f, $m)) { http_response_code(404); header_remove('Content-Type'); header('Content-Type: text/plain'); echo 'Sem foto.'; exit; }
    header_remove('Content-Type'); header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=3600'); echo base64_decode($m[1]); exit;
}
default:
    erro('Ação inválida.', 404);
}
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) $pdo->rollBack();
    erro('Erro no servidor: ' . $e->getMessage(), 500);
}
