<?php
// smtp_mail.php — cliente SMTP mínimo, sem dependências (usado pela Escala e pelo Ponto; o arquivo é o mesmo nos dois).
// Suporta conexão direta SSL (porta 465), STARTTLS (587) ou sem criptografia (25), autenticação LOGIN/PLAIN, HTML + texto e anexos.
// A configuração fica na tabela smtp_config (uma linha) do banco compartilhado; a senha nunca é devolvida às telas.

function smtpGarantirTabela($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS smtp_config (
        id INT PRIMARY KEY DEFAULT 1, host VARCHAR(200), porta INT NOT NULL DEFAULT 587, seguranca VARCHAR(10) NOT NULL DEFAULT 'tls',
        usuario VARCHAR(200), senha TEXT, remetente VARCHAR(200), remetente_nome VARCHAR(200), verificar_cert BOOLEAN NOT NULL DEFAULT TRUE,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, CONSTRAINT smtp_uma_linha CHECK (id = 1))");
}
function smtpConfigLer($pdo) {
    smtpGarantirTabela($pdo);
    $r = $pdo->query("SELECT * FROM smtp_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC);
    return $r ?: ['host' => '', 'porta' => 587, 'seguranca' => 'tls', 'usuario' => '', 'senha' => '', 'remetente' => '', 'remetente_nome' => 'SmartNOC Ponto', 'verificar_cert' => true];
}
function smtpConfigurado($cfg) { return !empty($cfg['host']) && !empty($cfg['remetente']) && filter_var($cfg['remetente'], FILTER_VALIDATE_EMAIL); }
function smtpConfigPublica($cfg) { // o que a tela recebe: nunca a senha
    return ['host' => $cfg['host'] ?? '', 'porta' => (int)($cfg['porta'] ?? 587), 'seguranca' => $cfg['seguranca'] ?? 'tls', 'usuario' => $cfg['usuario'] ?? '',
            'tem_senha' => !empty($cfg['senha']), 'remetente' => $cfg['remetente'] ?? '', 'remetente_nome' => $cfg['remetente_nome'] ?? '',
            'verificar_cert' => !isset($cfg['verificar_cert']) || (bool)$cfg['verificar_cert'], 'configurado' => smtpConfigurado($cfg)];
}
function smtpConfigSalvar($pdo, $in) { // devolve mensagem de erro ou null; senha em branco mantém a atual
    $host = trim((string)($in['host'] ?? '')); $porta = (int)($in['porta'] ?? 0); $seg = (string)($in['seguranca'] ?? 'tls');
    $usuario = trim((string)($in['usuario'] ?? '')); $rem = trim((string)($in['remetente'] ?? '')); $nome = trim((string)($in['remetente_nome'] ?? ''));
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+$/', $host)) return 'Informe o servidor SMTP (ex.: smtp.office365.com).';
    if ($porta < 1 || $porta > 65535) return 'Porta inválida.';
    if (!in_array($seg, ['ssl', 'tls', 'none'], true)) return 'Segurança inválida.';
    if (!filter_var($rem, FILTER_VALIDATE_EMAIL)) return 'Informe um e-mail de remetente válido.';
    $nome = preg_replace('/[\r\n]+/', ' ', mb_substr($nome, 0, 80));
    $atual = smtpConfigLer($pdo);
    $senha = (string)($in['senha'] ?? '');
    if ($senha === '') $senha = (string)($atual['senha'] ?? '');
    if (!empty($in['limpar_senha'])) $senha = '';
    $pdo->prepare("INSERT INTO smtp_config (id, host, porta, seguranca, usuario, senha, remetente, remetente_nome, verificar_cert, atualizado_em)
        VALUES (1, :h, :p, :s, :u, :pw, :r, :n, :v, now())
        ON CONFLICT (id) DO UPDATE SET host = EXCLUDED.host, porta = EXCLUDED.porta, seguranca = EXCLUDED.seguranca, usuario = EXCLUDED.usuario,
            senha = EXCLUDED.senha, remetente = EXCLUDED.remetente, remetente_nome = EXCLUDED.remetente_nome, verificar_cert = EXCLUDED.verificar_cert, atualizado_em = now()")
        ->execute(['h' => $host, 'p' => $porta, 's' => $seg, 'u' => $usuario, 'pw' => $senha, 'r' => $rem, 'n' => $nome, 'v' => !empty($in['verificar_cert']) ? 'true' : 'false']);
    return null;
}

function smtpLer($fp) { // resposta SMTP (pode ter várias linhas "250-..." terminando em "250 ...")
    $resp = '';
    while (($l = fgets($fp, 1024)) !== false) { $resp .= $l; if (strlen($l) < 4 || $l[3] !== '-') break; }
    return $resp;
}
function smtpCmd($fp, $cmd, $esperado) {
    if ($cmd !== null) fwrite($fp, $cmd . "\r\n");
    $r = smtpLer($fp);
    if ($r === '' || !in_array((int)substr($r, 0, 3), (array)$esperado, true)) throw new Exception('SMTP: ' . trim($r !== '' ? $r : 'sem resposta do servidor'));
    return $r;
}
function smtpCab($s) { return preg_replace('/[\r\n]+/', ' ', (string)$s); }
function smtpEnc($s) { return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s; }

// $anexos: [['nome' => 'folha.pdf', 'tipo' => 'application/pdf', 'dados' => <binário>]]. Devolve ['ok' => bool, 'erro' => string].
function smtpEnviar($cfg, $para, $assunto, $html, $texto = '', $anexos = []) {
    $fp = null;
    try {
        if (!smtpConfigurado($cfg)) throw new Exception('SMTP não configurado. Preencha em Escala → Configurações → E-mail (SMTP).');
        $para = smtpCab(trim($para));
        if (!filter_var($para, FILTER_VALIDATE_EMAIL)) throw new Exception('E-mail do destinatário inválido.');
        $host = $cfg['host']; $porta = (int)$cfg['porta']; $seg = $cfg['seguranca'];
        $ver = !isset($cfg['verificar_cert']) || (bool)$cfg['verificar_cert'];
        $ctx = stream_context_create(['ssl' => ['verify_peer' => $ver, 'verify_peer_name' => $ver, 'allow_self_signed' => !$ver, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $fp = @stream_socket_client(($seg === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $porta, $en, $es, 15, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) { $d = trim(preg_replace('/^stream_socket_client\(\):\s*/', '', (string)(error_get_last()['message'] ?? ''))); throw new Exception("Não foi possível conectar em $host:$porta (" . ($es ? "$es " : '') . $d . ')' . ($seg === 'ssl' && $ver ? ' Se o certificado é autoassinado, desmarque "Verificar certificado".' : '')); }
        stream_set_timeout($fp, 20);
        smtpCmd($fp, null, 220);
        $eu = preg_replace('/[^A-Za-z0-9.\-]/', '', (string)(gethostname() ?: 'localhost')) ?: 'localhost';
        smtpCmd($fp, "EHLO $eu", 250);
        if ($seg === 'tls') {
            smtpCmd($fp, 'STARTTLS', 220);
            if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT') ? STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT : 0)))
                throw new Exception('Falha ao negociar TLS (STARTTLS). Se o certificado é autoassinado, desmarque "Verificar certificado".');
            smtpCmd($fp, "EHLO $eu", 250);
        }
        if (($cfg['usuario'] ?? '') !== '') {
            $r = smtpCmd($fp, 'AUTH LOGIN', [334, 235, 504, 502, 535]);
            if ((int)substr($r, 0, 3) === 334) {
                smtpCmd($fp, base64_encode($cfg['usuario']), 334);
                smtpCmd($fp, base64_encode((string)$cfg['senha']), 235);
            } else { // servidor sem LOGIN: tenta PLAIN
                smtpCmd($fp, 'AUTH PLAIN ' . base64_encode("\0" . $cfg['usuario'] . "\0" . $cfg['senha']), 235);
            }
        }
        $de = smtpCab($cfg['remetente']);
        smtpCmd($fp, "MAIL FROM:<$de>", 250);
        smtpCmd($fp, "RCPT TO:<$para>", [250, 251]);
        smtpCmd($fp, 'DATA', 354);
        $b1 = 'mix_' . bin2hex(random_bytes(8)); $b2 = 'alt_' . bin2hex(random_bytes(8));
        $dom = substr(strrchr($de, '@'), 1);
        $cab = 'From: ' . smtpEnc(smtpCab($cfg['remetente_nome'] ?? '')) . ' <' . $de . ">\r\nTo: <$para>\r\nSubject: " . smtpEnc(smtpCab($assunto)) . "\r\nDate: " . date('r') .
               "\r\nMessage-ID: <" . bin2hex(random_bytes(12)) . "@$dom>\r\nMIME-Version: 1.0\r\nX-Auto-Response-Suppress: All\r\n";
        if ($texto === '') $texto = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/div|/li|/tr|/h\d)[^>]*>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $alt = "--$b2\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($texto)) .
               "--$b2\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "--$b2--\r\n";
        if ($anexos) {
            $corpo = "--$b1\r\nContent-Type: multipart/alternative; boundary=\"$b2\"\r\n\r\n" . $alt;
            foreach ($anexos as $a) {
                $n = smtpCab($a['nome']);
                $corpo .= "--$b1\r\nContent-Type: " . smtpCab($a['tipo'] ?? 'application/octet-stream') . "; name=\"$n\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$n\"\r\n\r\n" . chunk_split(base64_encode($a['dados']));
            }
            $corpo .= "--$b1--\r\n";
            $cab .= "Content-Type: multipart/mixed; boundary=\"$b1\"\r\n";
        } else { $corpo = $alt; $cab .= "Content-Type: multipart/alternative; boundary=\"$b2\"\r\n"; }
        fwrite($fp, $cab . "\r\n" . $corpo . "\r\n.\r\n");
        smtpCmd($fp, null, 250);
        @fwrite($fp, "QUIT\r\n"); @fclose($fp);
        return ['ok' => true, 'erro' => ''];
    } catch (Exception $e) {
        if (is_resource($fp)) { @fwrite($fp, "QUIT\r\n"); @fclose($fp); }
        return ['ok' => false, 'erro' => $e->getMessage()];
    }
}
