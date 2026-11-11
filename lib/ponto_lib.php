<?php
// ponto_lib.php — Ponto eletrônico com reconhecimento facial (comparação no servidor), geolocalização,
// regra de horário configurável, alertas de batidas ímpares/faltantes e batidas manuais com aprovação.
// Incluído por api_ponto.php. Não imprime nada por si só.

date_default_timezone_set('America/Sao_Paulo');

const PT_TIPOS = ['ENTRADA', 'SAIDA_ALMOCO', 'RETORNO_ALMOCO', 'SAIDA'];
const PT_TIPOS_EXTRA = ['EXTRA_ENTRADA', 'EXTRA_SAIDA']; // período de hora extra incluído manualmente, depois do fim de expediente
const PT_ORDEM = ['ENTRADA', 'SAIDA_ALMOCO', 'RETORNO_ALMOCO', 'SAIDA', 'EXTRA_ENTRADA', 'EXTRA_SAIDA'];
const PT_LABEL = ['ENTRADA' => 'Início da atividade', 'SAIDA_ALMOCO' => 'Saída para o almoço',
                  'RETORNO_ALMOCO' => 'Retorno do almoço', 'SAIDA' => 'Fim de expediente',
                  'EXTRA_ENTRADA' => 'Início da hora extra', 'EXTRA_SAIDA' => 'Fim da hora extra'];
const PT_LABEL_FDS = ['ENTRADA' => 'Entrada', 'SAIDA' => 'Saída', 'EXTRA_ENTRADA' => 'Entrada (2º período)', 'EXTRA_SAIDA' => 'Saída (2º período)'];
const PT_FLEX_MAX = 120;        // flexibilidade máxima de horário: 2 horas antes ou depois do horário padrão
const PT_DIST_MAX = 0.5;        // distância euclidiana máxima entre descritores faciais (menor = mais parecido)
const PT_JANELA_DIAS = 35;      // quantos dias para trás o colaborador pode corrigir/ver alertas
const PT_ATIVOS = "('valida','pendente','aprovada')";
const PT_BLOQUEAR_AUTOAPROVACAO = false; // true = gestor não aprova/recusa a própria solicitação (false: liberado para demonstração)
const PT_HORAS_PREVISTAS_MIN = 540; // 09:00 por dia com expediente
const PT_TOLERANCIA_MIN = 5;        // até 5 min a mais/menos não gera hora extra nem hora a pagar (o saldo continua contando)
const PT_DIA_CORTE = 15;        // a folha de um mês vai do dia 15 do mês anterior ao dia 14 do mês

function ptGarantirTabelas($pdo) {
    try { ptBancoGarantir($pdo); } catch (Exception $e) {}
    try { if (!$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'funcionarios' AND column_name = 'data_admissao'")->fetchColumn()) $pdo->exec("ALTER TABLE funcionarios ADD COLUMN IF NOT EXISTS data_admissao DATE"); } catch (Exception $e) {} // só altera a tabela se faltar a coluna (evita bloqueio a cada requisição) // cadastrada na Escala (Editar Colaborador)
    $pdo->exec("CREATE TABLE IF NOT EXISTS configuracoes (chave VARCHAR(100) PRIMARY KEY, valor TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS trilha_auditoria (
        id SERIAL PRIMARY KEY, data_hora TIMESTAMP DEFAULT CURRENT_TIMESTAMP, usuario VARCHAR(100), acao VARCHAR(100), detalhes TEXT)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_login_falhas (
        chave VARCHAR(120) PRIMARY KEY, tentativas INT NOT NULL DEFAULT 0, bloqueado_ate TIMESTAMP, atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_colaboradores (
        nome VARCHAR(150) PRIMARY KEY,
        pin_hash TEXT,
        pin_provisorio BOOLEAN NOT NULL DEFAULT TRUE,
        descritor TEXT,
        foto_cadastro TEXT,
        cadastrado_em TIMESTAMP,
        ativo BOOLEAN NOT NULL DEFAULT TRUE,
        tentativas INT NOT NULL DEFAULT 0,
        bloqueado_ate TIMESTAMP,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_batidas (
        id SERIAL PRIMARY KEY,
        nome VARCHAR(150) NOT NULL,
        data_ref DATE NOT NULL,
        tipo VARCHAR(20) NOT NULL,
        momento TIMESTAMP NOT NULL,
        origem VARCHAR(10) NOT NULL,
        foto TEXT,
        lat DOUBLE PRECISION,
        lng DOUBLE PRECISION,
        precisao REAL,
        distancia REAL,
        justificativa TEXT,
        status VARCHAR(10) NOT NULL DEFAULT 'valida',
        decidido_por VARCHAR(100),
        decidido_em TIMESTAMP,
        motivo_decisao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $c = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'ponto_batidas' AND column_name = 'remocao_status'")->fetchColumn();
    if (!$c) $pdo->exec("ALTER TABLE ponto_batidas ADD COLUMN IF NOT EXISTS remocao_status VARCHAR(10), ADD COLUMN IF NOT EXISTS remocao_justificativa TEXT,
        ADD COLUMN IF NOT EXISTS remocao_em TIMESTAMP, ADD COLUMN IF NOT EXISTS remocao_decidido_por VARCHAR(100),
        ADD COLUMN IF NOT EXISTS remocao_decidido_em TIMESTAMP, ADD COLUMN IF NOT EXISTS remocao_motivo TEXT");
    $c = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'ponto_colaboradores' AND column_name = 'regra'")->fetchColumn();
    if (!$c) $pdo->exec("ALTER TABLE ponto_colaboradores ADD COLUMN IF NOT EXISTS regra TEXT"); // regra de horário individual (JSON); NULL = usa a padrão
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_vidpol (
        id SERIAL PRIMARY KEY, nome VARCHAR(150) NOT NULL, data_ref DATE NOT NULL, vidpol VARCHAR(6) NOT NULL,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (nome, data_ref, vidpol))");
    $novoEv = !$pdo->query("SELECT to_regclass('ponto_eventos')")->fetchColumn();
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_eventos ( -- histórico de auditoria (somente inserção)
        id SERIAL PRIMARY KEY, batida_id INT, nome VARCHAR(150) NOT NULL, data_ref DATE NOT NULL, evento VARCHAR(30) NOT NULL,
        tipo VARCHAR(20), momento TIMESTAMP, usuario VARCHAR(100), detalhes TEXT, ip VARCHAR(64), agente VARCHAR(200),
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pe_nome_data ON ponto_eventos (nome, data_ref)");
    if ($novoEv) { // reconstrói o histórico dos registros que já existiam
        $pdo->exec("INSERT INTO ponto_eventos (batida_id, nome, data_ref, evento, tipo, momento, usuario, detalhes, criado_em)
            SELECT id, nome, data_ref, 'BATIDA_FACIAL', tipo, momento, nome,
                   'Reconhecimento facial' || CASE WHEN distancia IS NOT NULL THEN ' (distância ' || round(distancia::numeric, 3) || ')' ELSE '' END, criado_em
              FROM ponto_batidas WHERE origem = 'facial'
            UNION ALL
            SELECT id, nome, data_ref, 'INCLUSAO_SOLICITADA', tipo, momento, nome, justificativa, criado_em FROM ponto_batidas WHERE origem <> 'facial'
            UNION ALL
            SELECT id, nome, data_ref, CASE status WHEN 'aprovada' THEN 'INCLUSAO_APROVADA' WHEN 'recusada' THEN 'INCLUSAO_RECUSADA' ELSE 'INCLUSAO_CANCELADA' END,
                   tipo, momento, decidido_por, motivo_decisao, COALESCE(decidido_em, criado_em)
              FROM ponto_batidas WHERE origem <> 'facial' AND status IN ('aprovada','recusada','cancelada')
            UNION ALL
            SELECT id, nome, data_ref, 'REMOCAO_SOLICITADA', tipo, momento, nome, remocao_justificativa, remocao_em
              FROM ponto_batidas WHERE remocao_status IS NOT NULL AND remocao_em IS NOT NULL
            UNION ALL
            SELECT id, nome, data_ref, CASE remocao_status WHEN 'aprovada' THEN 'REMOCAO_APROVADA' ELSE 'REMOCAO_RECUSADA' END,
                   tipo, momento, remocao_decidido_por, remocao_motivo, COALESCE(remocao_decidido_em, remocao_em)
              FROM ponto_batidas WHERE remocao_status IN ('aprovada','recusada')");
    }
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pb_nome_data ON ponto_batidas (nome, data_ref)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS uq_pb_ativa ON ponto_batidas (nome, data_ref, tipo) WHERE status IN ('valida','pendente','aprovada')");
}

// ---------- utilitários ----------

function ptMin($hhmm) {
    if (!is_string($hhmm) || !preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) return null;
    return (int)$m[1] * 60 + (int)$m[2];
}
function ptHHMM($min) { $min = max(0, min(1439, (int)round($min))); return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function ptDataValida($d) {
    if (!is_string($d) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return false;
    [$y, $m, $dd] = array_map('intval', explode('-', $d));
    return checkdate($m, $dd, $y);
}
function ptMinDoMomento($ts) { return (int)date('G', strtotime($ts)) * 60 + (int)date('i', strtotime($ts)); }

// ---------- regra de horário ----------

function ptRegraPadrao() {
    $dia = function ($fim) { return ['ini' => '08:00', 'alm_ini' => '12:00', 'alm_fim' => '13:00', 'fim' => $fim]; };
    return ['tolerancia' => 10, 'flex_min' => 0, 'almoco_auto' => false, 'banco_horas' => false, 'banco_inicio' => null, 'dias' => [
        '0' => null, '1' => $dia('18:00'), '2' => $dia('18:00'), '3' => $dia('18:00'), '4' => $dia('18:00'),
        '5' => $dia('17:00'), '6' => null]];
}

function ptRegraValidar($r) {
    if (!is_array($r) || !isset($r['dias']) || !is_array($r['dias'])) return 'Regra inválida.';
    $tol = $r['tolerancia'] ?? 10;
    if (!is_numeric($tol) || $tol < 0 || $tol > 60) return 'A tolerância deve ficar entre 0 e 60 minutos.';
    $flex = $r['flex_min'] ?? 0;
    if (!is_numeric($flex) || $flex < 0 || $flex > PT_FLEX_MAX) return 'A flexibilidade de horário deve ficar entre 0 e ' . PT_FLEX_MAX . ' minutos (até 2 horas antes ou depois do horário padrão).';
    $nomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
    for ($i = 0; $i < 7; $i++) {
        $d = $r['dias'][(string)$i] ?? null;
        if ($d === null) continue;
        $a = ptMin($d['ini'] ?? null); $b = ptMin($d['alm_ini'] ?? null); $c = ptMin($d['alm_fim'] ?? null); $e = ptMin($d['fim'] ?? null);
        if ($a === null || $b === null || $c === null || $e === null) return $nomes[$i] . ': informe os quatro horários (HH:MM).';
        if (!($a < $b && $b < $c && $c < $e)) return $nomes[$i] . ': os horários devem seguir a ordem início < saída almoço < retorno < fim.';
    }
    return null;
}

function ptRegraGlobal($pdo) {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ponto_regra'");
    $st->execute();
    $v = $st->fetchColumn();
    $r = $v ? json_decode($v, true) : null;
    if (!is_array($r) || ptRegraValidar($r)) return ptRegraPadrao();
    return $r;
}
// Regra válida para um colaborador: a individual (se houver) ou a padrão. Sem $nome, a padrão.
function ptRegra($pdo, $nome = null) {
    $g = ptRegraGlobal($pdo);
    if ($nome === null) return $g;
    $st = $pdo->prepare("SELECT regra FROM ponto_colaboradores WHERE nome = :n");
    $st->execute(['n' => $nome]);
    $v = $st->fetchColumn();
    $r = $v ? json_decode($v, true) : null;
    if (!is_array($r) || ptRegraValidar($r)) return $g;
    $r['individual'] = true;
    return $r;
}

function ptRegraLimpa($r) {
    $limpa = ['tolerancia' => (int)($r['tolerancia'] ?? 10), 'flex_min' => (int)($r['flex_min'] ?? 0), 'almoco_auto' => !empty($r['almoco_auto']), 'banco_horas' => !empty($r['banco_horas']),
              'banco_inicio' => !empty($r['banco_horas']) ? (ptDataValida((string)($r['banco_inicio'] ?? '')) ? $r['banco_inicio'] : ptFolhaPeriodo(ptFolhaRefDe(date('Y-m-d')))[0]) : null, 'dias' => []];
    for ($i = 0; $i < 7; $i++) {
        $d = $r['dias'][(string)$i] ?? null;
        $limpa['dias'][(string)$i] = $d === null ? null :
            ['ini' => $d['ini'], 'alm_ini' => $d['alm_ini'], 'alm_fim' => $d['alm_fim'], 'fim' => $d['fim']];
    }
    return $limpa;
}
function ptRegraSalvar($pdo, $r) {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ponto_regra', :v)
                   ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => json_encode(ptRegraLimpa($r))]);
}
function ptRegraSalvarColab($pdo, $nome, $r) { // $r = null volta para a regra padrão
    $pdo->prepare("INSERT INTO ponto_colaboradores (nome) VALUES (:n) ON CONFLICT (nome) DO NOTHING")->execute(['n' => $nome]);
    $pdo->prepare("UPDATE ponto_colaboradores SET regra = :v WHERE nome = :n")
        ->execute(['v' => $r === null ? null : json_encode(ptRegraLimpa($r)), 'n' => $nome]);
}

// ---------- batidas do dia, sequência e sugestões ----------

function ptBatidasDoDia($pdo, $nome, $data) {
    $st = $pdo->prepare("SELECT id, tipo, to_char(momento,'YYYY-MM-DD HH24:MI:SS') AS momento, origem, status, justificativa,
                                lat, lng, precisao, distancia, offline, (foto IS NOT NULL) AS tem_foto, motivo_decisao, decidido_por,
                                remocao_status, remocao_justificativa, remocao_motivo
                         FROM ponto_batidas WHERE nome = :n AND data_ref = :d ORDER BY momento, id");
    $st->execute(['n' => $nome, 'd' => $data]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
function ptAtivas($batidas) {
    return array_values(array_filter($batidas, function ($b) { return in_array($b['status'], ['valida', 'pendente', 'aprovada'], true); }));
}
function ptProximoTipo($batidas) {
    $tem = array_column(ptAtivas($batidas), 'tipo');
    foreach (PT_TIPOS as $t) if (!in_array($t, $tem, true)) return $t;
    return null;
}

// PT_FDS_V1: em dia sem expediente (fim de semana, feriado, folga) os registros são pares de entrada e saída:
// 1º período = ENTRADA/SAIDA; se houver pausa (ex.: almoço), o 2º período = EXTRA_ENTRADA/EXTRA_SAIDA.
const PT_SEQ_FDS = ['ENTRADA', 'SAIDA', 'EXTRA_ENTRADA', 'EXTRA_SAIDA'];
function ptDiaFds($batidas, $semExp) { // dias antigos que já têm almoço registrado seguem o modelo de 4 batidas
    if (!$semExp) return false;
    $tem = array_column(ptAtivas($batidas), 'tipo');
    return !in_array('SAIDA_ALMOCO', $tem, true) && !in_array('RETORNO_ALMOCO', $tem, true);
}
function ptProximoTipoFds($batidas) {
    $tem = array_column(ptAtivas($batidas), 'tipo');
    foreach (PT_SEQ_FDS as $t) if (!in_array($t, $tem, true)) return $t;
    return null;
}
// Almoço automático (regra de horário, opcional): dia de expediente com só entrada e saída (válidas/aprovadas) recebe o
// almoço da regra, acompanhando o horário real de entrada, sem aprovação do gestor. Retorna true se registrou.
function ptAlmocoAuto($pdo, $nome, $data) {
    $regra = ptRegra($pdo, $nome);
    if (empty($regra['almoco_auto'])) return false;
    $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
    if (!$r || ptDiaSemObrigacao($pdo, $nome, $data)) return false;
    $m = [];
    foreach (ptAtivas(ptBatidasDoDia($pdo, $nome, $data)) as $b) $m[$b['tipo']] = $b;
    if (!isset($m['ENTRADA'], $m['SAIDA']) || isset($m['SAIDA_ALMOCO']) || isset($m['RETORNO_ALMOCO'])) return false;
    if ($m['ENTRADA']['status'] === 'pendente' || $m['SAIDA']['status'] === 'pendente') return false;
    $zero = strtotime($data . ' 00:00:00');
    $e = (int)round((strtotime($m['ENTRADA']['momento']) - $zero) / 60); $s = (int)round((strtotime($m['SAIDA']['momento']) - $zero) / 60);
    $dur = ptMin($r['alm_fim']) - ptMin($r['alm_ini']);
    if ($dur <= 0 || $s - $e < $dur + 120) return false;
    $ai = ptMin($r['alm_ini']) + ($e - ptMin($r['ini'])); // acompanha a entrada real (flexibilidade)
    $ai = max($ai, $e + 60); if ($ai + $dur > $s - 60) $ai = $s - 60 - $dur;
    if ($ai <= $e || $ai + $dur >= $s || $ai + $dur >= 1440) return false;
    $ins = $pdo->prepare("INSERT INTO ponto_batidas (nome, data_ref, tipo, momento, origem, justificativa, status) VALUES (:n, :d, :t, :m, 'sistema', :j, 'valida') RETURNING id");
    foreach (['SAIDA_ALMOCO' => $ai, 'RETORNO_ALMOCO' => $ai + $dur] as $tp => $min) {
        $mom = $data . ' ' . ptHHMM($min) . ':00';
        $ins->execute(['n' => $nome, 'd' => $data, 't' => $tp, 'm' => $mom, 'j' => 'Almoço registrado automaticamente pelo sistema (regra de horário).']);
        $id = (int)$ins->fetchColumn();
        if (function_exists('evento')) evento($pdo, $id, $nome, $data, 'ALMOCO_AUTOMATICO', $tp, $mom, 'sistema', 'Almoço registrado automaticamente pela regra de horário');
    }
    ptAgendarSyncHE($nome, $data);
    return true;
}

// Verifica se $momento (Y-m-d H:i:s) respeita a ordem cronológica das demais batidas ativas do dia
function ptValidarOrdem($batidas, $tipo, $momento) {
    $idx = array_search($tipo, PT_ORDEM, true);
    $ts = strtotime($momento);
    foreach (ptAtivas($batidas) as $b) {
        $j = array_search($b['tipo'], PT_ORDEM, true);
        $tb = strtotime($b['momento']);
        if ($j < $idx && $tb >= $ts) return 'O horário deve ser posterior a "' . PT_LABEL[$b['tipo']] . '" (' . date('H:i', $tb) . ').';
        if ($j > $idx && $tb <= $ts) return 'O horário deve ser anterior a "' . PT_LABEL[$b['tipo']] . '" (' . date('H:i', $tb) . ').';
    }
    return null;
}

// Sugere horários para as batidas que faltam, a partir da regra e das batidas já feitas no dia.
// Ex.: entrou 08:20 e fez 1h de almoço => a saída sugerida é 18:20 (cumpre a jornada líquida da regra).
function ptSugestoes($regra, $data, $batidas) {
    $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
    if (!$r) return [];
    $ini = ptMin($r['ini']); $ai = ptMin($r['alm_ini']); $af = ptMin($r['alm_fim']); $fim = ptMin($r['fim']);
    $jornada = ($fim - $ini) - ($af - $ai);
    $almDur = $af - $ai;
    $real = [];
    foreach (ptAtivas($batidas) as $b) $real[$b['tipo']] = ptMinDoMomento($b['momento']);

    $e = $real['ENTRADA'] ?? $ini;
    $sa = $real['SAIDA_ALMOCO'] ?? max($ai, $e + 1);
    $ra = $real['RETORNO_ALMOCO'] ?? ($sa + $almDur);
    $s = $real['SAIDA'] ?? ($ra + max(0, $jornada - ($sa - $e)));
    $calc = ['ENTRADA' => $e, 'SAIDA_ALMOCO' => $sa, 'RETORNO_ALMOCO' => $ra, 'SAIDA' => $s];
    $out = [];
    foreach (PT_TIPOS as $t) if (!isset($real[$t])) $out[$t] = ptHHMM($calc[$t]);
    return $out;
}

function ptPascoa($ano) { // algoritmo de Gauss/Meeus (não depende da extensão calendar do PHP)
    $a = $ano % 19; $b = intdiv($ano, 100); $c = $ano % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $mes = intdiv($h + $l - 7 * $m + 114, 31); $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
}
function ptLen($s) { return function_exists('mb_strlen') ? mb_strlen($s) : strlen($s); }

// Feriados fixos cadastrados + móveis nacionais principais: dia sem obrigação de ponto
// FERIADOS_LOCAIS_V1: nacionais valem para todos; estaduais para a UF do "Local de prestação de serviço" (Arquivos fiscais);
// municipais para o colaborador cuja "Localidade" (cadastro da Escala) é a cidade do feriado. Cadastro em Escala → Configurações → Feriados.
function ptLocNorm($s) { // CIDADE_NORM_V1: compara cidades sem acento e sem "/UF" ("VITÓRIA DA CONQUISTA/BA" = "Vitoria da Conquista")
    $s = trim(function_exists('mb_strtoupper') ? mb_strtoupper((string)$s, 'UTF-8') : strtoupper((string)$s));
    $s = strtr($s, ['Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'É' => 'E', 'Ê' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ç' => 'C']);
    return trim(preg_replace('~\s*[/\-]\s*[A-Z]{2}$~', '', $s));
}
function ptFeriadosCad($pdo) { // [MM-DD => [[tipo, nome, loc], ...]] — lido uma vez por requisição
    static $c = null; if ($c !== null) return $c;
    $c = [];
    try {
        $q = $pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'feriados' AND column_name = 'localidade'");
        $temLoc = (bool)$q->fetchColumn();
        $q = $pdo->query("SELECT to_char(data_feriado,'MM-DD') AS md, lower(COALESCE(tipo, 'nacional')) AS tipo, COALESCE(nome, '') AS nome, " . ($temLoc ? "COALESCE(localidade, '')" : "''") . " AS loc
                          FROM feriados WHERE data_feriado IS NOT NULL" . ($temLoc ? '' : " AND tipo = 'nacional'"));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $c[$r['md']][] = $r;
    } catch (Exception $e) {}
    return $c;
}
function ptUfEmpresa($pdo) { // UF do local de prestação de serviço (ex.: "Salvador/BA" => BA); padrão BA
    static $uf = null; if ($uf !== null) return $uf;
    $uf = 'BA';
    try { $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ponto_fiscal'"); $q->execute(); $v = json_decode((string)$q->fetchColumn(), true);
          if (is_array($v) && preg_match('/([A-Za-z]{2})\s*$/', trim((string)($v['local'] ?? '')), $m)) $uf = strtoupper($m[1]); } catch (Exception $e) {}
    return $uf;
}
function ptLocalidadeColab($pdo, $nome) { // cidade do colaborador (campo Localidade da Escala)
    static $c = [];
    if ($nome === null || $nome === '') return '';
    if (!array_key_exists($nome, $c)) {
        try { $st = $pdo->prepare("SELECT localidade FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]); $c[$nome] = ptLocNorm($st->fetchColumn() ?: ''); }
        catch (Exception $e) { $c[$nome] = ''; }
    }
    return $c[$nome];
}
function ptFeriadoInfo($pdo, $data, $nome = null) { // ['tipo' => nacional|estadual|municipal|movel, 'nome' => ...] ou null
    $lista = ptFeriadosCad($pdo)[substr($data, 5, 5)] ?? [];
    foreach (['nacional', 'estadual', 'municipal'] as $t) foreach ($lista as $f) {
        if ($f['tipo'] !== $t) continue;
        if ($t === 'estadual' && ptLocNorm($f['loc']) !== ptUfEmpresa($pdo)) continue;
        if ($t === 'municipal') { $cid = ptLocalidadeColab($pdo, $nome); if ($cid === '' || ptLocNorm($f['loc']) !== $cid) continue; }
        return ['tipo' => $t, 'nome' => trim($f['nome'])];
    }
    $pasc = ptPascoa((int)substr($data, 0, 4));
    foreach ([-47 => 'Carnaval', -2 => 'Sexta-feira Santa', 60 => 'Corpus Christi'] as $off => $n)
        if (date('Y-m-d', strtotime($pasc . ' ' . sprintf('%+d', $off) . ' days')) === $data) return ['tipo' => 'movel', 'nome' => $n];
    return null;
}
function ptEhFeriado($pdo, $data, $nome = null) { return ptFeriadoInfo($pdo, $data, $nome) !== null; }
function ptFeriadoNome($pdo, $data, $nome = null) { // ex.: "Feriado Municipal: Aniversário da cidade de Salvador/BA"
    $f = ptFeriadoInfo($pdo, $data, $nome); if (!$f) return null;
    $rot = ['nacional' => 'Feriado Nacional', 'estadual' => 'Feriado Estadual', 'municipal' => 'Feriado Municipal', 'movel' => 'Feriado'][$f['tipo']];
    return $f['nome'] !== '' ? $rot . ': ' . $f['nome'] : $rot;
}
// versões antigas (só feriados nacionais), mantidas sem uso
function ptEhFeriadoV0($pdo, $data) {
    $st = $pdo->prepare("SELECT 1 FROM feriados WHERE tipo = 'nacional' AND to_char(data_feriado,'MM-DD') = :md LIMIT 1");
    try { $st->execute(['md' => substr($data, 5, 5)]); if ($st->fetchColumn()) return true; } catch (Exception $e) {}
    $pasc = ptPascoa((int)substr($data, 0, 4));
    foreach ([-47, -2, 60] as $off) if (date('Y-m-d', strtotime($pasc . ' ' . sprintf('%+d', $off) . ' days')) === $data) return true;
    return false;
}
function ptFeriadoNomeV0($pdo, $data) { // texto do feriado do dia (ex.: "Feriado Nacional: Proclamação da República"); null se não for feriado
    try {
        $st = $pdo->prepare("SELECT nome FROM feriados WHERE tipo = 'nacional' AND to_char(data_feriado,'MM-DD') = :md LIMIT 1");
        $st->execute(['md' => substr($data, 5, 5)]); $r = $st->fetch(PDO::FETCH_ASSOC);
        if ($r) return trim((string)$r['nome']) !== '' ? 'Feriado Nacional: ' . trim($r['nome']) : 'Feriado Nacional';
    } catch (Exception $e) {}
    $pasc = ptPascoa((int)substr($data, 0, 4));
    foreach ([-47 => 'Carnaval', -2 => 'Sexta-feira Santa', 60 => 'Corpus Christi'] as $off => $n)
        if (date('Y-m-d', strtotime($pasc . ' ' . sprintf('%+d', $off) . ' days')) === $data) return 'Feriado: ' . $n;
    return null;
}
function ptAdmissao($pdo, $nome) { // data de admissão (YYYY-MM-DD) cadastrada na Escala; null se não informada
    static $c = [];
    if (array_key_exists($nome, $c)) return $c[$nome];
    try { $st = $pdo->prepare("SELECT to_char(data_admissao, 'YYYY-MM-DD') FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]); $v = $st->fetchColumn(); }
    catch (Exception $e) { $v = false; }
    return $c[$nome] = ($v ?: null);
}
// ---------- BANCO_HORAS_V1: banco de horas (opcional, Regra de horário) ----------
// Crédito: hora extra aprovada pelo gestor (vai para o banco, não para a Escala mensal). Débito: hora a pagar e folgas aprovadas.
// As horas de uma folha valem até o fim da 3ª folha seguinte; depois expiram. Débitos consomem primeiro as horas mais antigas.
const PT_BANCO_MIN_FOLGA = 540;      // 09:00 de saldo para poder pedir folga
const PT_BANCO_VALIDADE_FOLHAS = 3;  // folhas seguintes em que as horas ainda valem
function ptBancoGarantir($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_banco_folgas (id SERIAL PRIMARY KEY, nome VARCHAR(150) NOT NULL, data DATE NOT NULL, minutos INT NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pendente', justificativa TEXT, criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        decidido_por VARCHAR(100), decidido_em TIMESTAMP, motivo_decisao TEXT)");
    if ($pdo->query("SELECT to_regclass('ponto_he_dia')")->fetchColumn()
        && !$pdo->query("SELECT 1 FROM information_schema.columns WHERE table_name = 'ponto_he_dia' AND column_name = 'destino'")->fetchColumn())
        $pdo->exec("ALTER TABLE ponto_he_dia ADD COLUMN IF NOT EXISTS destino VARCHAR(10)");
}
function ptBancoAtivo($regra, $data = null) { return !empty($regra['banco_horas']) && (!$data || empty($regra['banco_inicio']) || $data >= $regra['banco_inicio']); }
function ptBancoFolgaAprovada($pdo, $nome, $data) {
    try { $st = $pdo->prepare("SELECT 1 FROM ponto_banco_folgas WHERE nome = :n AND data = :d AND status = 'aprovada' LIMIT 1"); $st->execute(['n' => $nome, 'd' => $data]); return (bool)$st->fetchColumn(); }
    catch (Exception $e) { return false; }
}
function ptBancoHoras($pdo, $nome, $hoje = null) {
    $hoje = $hoje ?: date('Y-m-d');
    $regra = ptRegra($pdo, $nome);
    if (empty($regra['banco_horas'])) return ['ativo' => false];
    $ini = !empty($regra['banco_inicio']) ? $regra['banco_inicio'] : ptFolhaPeriodo(ptFolhaRefDe($hoje))[0];
    $ad = ptAdmissao($pdo, $nome); if ($ad && $ad > $ini) $ini = $ad;
    $lim = ptFolhaPeriodo(ptFolhaDeslocar(ptFolhaRefDe($hoje), -12))[0]; if ($ini < $lim) $ini = $lim; // olha no máximo 12 folhas para trás
    $ev = [];
    $q = $pdo->prepare("SELECT to_char(data_ref,'YYYY-MM-DD') AS d, SUM(minutos) AS m FROM ponto_hora_extra WHERE nome = :n AND status = 'banco' AND data_ref >= :i GROUP BY 1");
    $q->execute(['n' => $nome, 'i' => $ini]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r)
        $ev[] = ['d' => $r['d'], 'o' => 0, 'tipo' => 'credito', 'min' => (int)$r['m'], 'exp' => ptFolhaPeriodo(ptFolhaDeslocar(ptFolhaRefDe($r['d']), PT_BANCO_VALIDADE_FOLHAS))[1]];
    if ($ini < $hoje) for ($ref = ptFolhaRefDe($ini); $ref <= ptFolhaRefDe($hoje); $ref = ptFolhaDeslocar($ref, 1)) {
        $f = ptFolha($pdo, $regra, $nome, $ref);
        foreach ($f['dias'] as $d) if ($d['data'] >= $ini && $d['data'] < $hoje && (int)($d['pagar_min'] ?? 0) > 0)
            $ev[] = ['d' => $d['data'], 'o' => 1, 'tipo' => 'hora_a_pagar', 'min' => (int)$d['pagar_min']];
    }
    $folgas = []; $pend = 0;
    $q = $pdo->prepare("SELECT id, to_char(data,'YYYY-MM-DD') AS data, minutos, status, justificativa, to_char(decidido_em,'YYYY-MM-DD') AS dec, motivo_decisao
                        FROM ponto_banco_folgas WHERE nome = :n AND (status IN ('pendente','aprovada') OR criado_em > now() - interval '60 days') ORDER BY data DESC, id DESC");
    $q->execute(['n' => $nome]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $f) {
        $f['id'] = (int)$f['id']; $f['minutos'] = (int)$f['minutos']; $folgas[] = $f;
        if ($f['status'] === 'pendente') $pend += $f['minutos'];
        if ($f['status'] === 'aprovada') $ev[] = ['d' => min($f['dec'] ?: $f['data'], $f['data']), 'o' => 2, 'tipo' => 'folga', 'min' => $f['minutos'], 'folga' => $f['data']];
    }
    usort($ev, function ($a, $b) { return strcmp($a['d'], $b['d']) ?: ($a['o'] <=> $b['o']); });
    $lotes = []; $deve = 0; $expirado = 0; $mov = [];
    $expirar = function ($ate) use (&$lotes, &$expirado, &$mov) {
        foreach ($lotes as $k => $l) if ($l['exp'] < $ate) { $expirado += $l['min']; $mov[] = ['data' => $l['exp'], 'tipo' => 'expirado', 'min' => -$l['min']]; unset($lotes[$k]); }
        $lotes = array_values($lotes);
    };
    foreach ($ev as $e) {
        $expirar($e['d']);
        $m = $e['min'];
        if ($e['tipo'] === 'credito') {
            $p = min($deve, $m); $deve -= $p; $m -= $p;
            if ($m > 0) $lotes[] = ['min' => $m, 'exp' => $e['exp']];
            $mov[] = ['data' => $e['d'], 'tipo' => 'credito', 'min' => $e['min']];
        } else {
            foreach ($lotes as $k => $l) { $u = min($l['min'], $m); $lotes[$k]['min'] -= $u; $m -= $u; if ($m <= 0) break; }
            $lotes = array_values(array_filter($lotes, function ($l) { return $l['min'] > 0; }));
            $deve += $m;
            $mov[] = ['data' => $e['d'], 'tipo' => $e['tipo'], 'min' => -$e['min']] + (isset($e['folga']) ? ['folga' => $e['folga']] : []);
        }
    }
    $expirar($hoje);
    $cred = array_sum(array_column($lotes, 'min'));
    $exp = []; foreach ($lotes as $l) $exp[$l['exp']] = ($exp[$l['exp']] ?? 0) + $l['min'];
    ksort($exp); $aExp = []; foreach ($exp as $d => $m) $aExp[] = ['data' => $d, 'min' => $m];
    usort($mov, function ($a, $b) { return strcmp($b['data'], $a['data']); });
    return ['ativo' => true, 'inicio' => $ini, 'saldo_min' => $cred - $deve, 'credito_min' => $cred, 'devedor_min' => $deve, 'expirado_min' => $expirado,
            'pendente_min' => $pend, 'disponivel_min' => $cred - $deve - $pend, 'minimo_folga' => PT_BANCO_MIN_FOLGA, 'validade_folhas' => PT_BANCO_VALIDADE_FOLHAS,
            'a_expirar' => $aExp, 'folgas' => $folgas, 'movimentos' => array_slice($mov, 0, 40)];
}
// pedidos de folga do banco como itens das telas de aprovação
function ptBancoFolgasItens($pdo, $status, $ini = null, $fim = null, $nomeLike = '%') {
    try {
        $in = implode(',', array_map(function ($s) { return "'" . preg_replace('/[^a-z]/', '', $s) . "'"; }, $status));
        $st = $pdo->prepare("SELECT id, nome, to_char(data,'YYYY-MM-DD') AS data, minutos, justificativa, to_char(criado_em,'YYYY-MM-DD HH24:MI') AS criado_em, status,
                                    decidido_por, to_char(decidido_em,'YYYY-MM-DD HH24:MI') AS decidido_em, motivo_decisao
                             FROM ponto_banco_folgas WHERE status IN ($in) AND (:i::date IS NULL OR data >= :i2::date) AND (:f::date IS NULL OR data <= :f2::date) AND nome ILIKE :n ORDER BY data");
        $st->execute(['i' => $ini, 'i2' => $ini, 'f' => $fim, 'f2' => $fim, 'n' => $nomeLike]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
            $m = (int)$x['minutos'];
            $out[] = ['id' => (int)$x['id'], 'nome' => $x['nome'], 'data' => $x['data'], 'tipo' => 'FOLGA_BANCO', 'hora' => 'Folga · ' . sprintf('%02d:%02d', intdiv($m, 60), $m % 60),
                      'justificativa' => $x['justificativa'] ?: 'Folga abatendo do banco de horas', 'criado_em' => $x['criado_em'], 'solic' => 'folga_banco', 'origem' => 'manual',
                      'tem_anexo' => false, 'rotulo' => 'Folga (banco de horas)', 'status' => $x['status'], 'decidido_por' => $x['decidido_por'], 'decidido_em' => $x['decidido_em'],
                      'motivo_decisao' => $x['motivo_decisao'], 'propria' => false, 'cancelavel' => $x['status'] === 'aprovada'];
        }
        return $out;
    } catch (Exception $e) { return []; }
}

function ptDiaSemObrigacao($pdo, $nome, $data) {
    if (ptBancoFolgaAprovada($pdo, $nome, $data)) return true; // folga do banco de horas aprovada
    $ad = ptAdmissao($pdo, $nome); if ($ad && $data < $ad) return true; // antes da admissão: sem jornada, sem cobrança, sem horas previstas
    if (ptEhFeriado($pdo, $data, $nome)) return true;
    try { // atestado médico de dia inteiro aprovado: o dia não tem obrigação de ponto
        $st = $pdo->prepare("SELECT 1 FROM ponto_atestados WHERE nome = :n AND tipo = 'dia' AND status = 'aprovada' AND data_ini <= :d AND data_fim >= :d LIMIT 1");
        $st->execute(['n' => $nome, 'd' => $data]);
        if ($st->fetchColumn()) return true;
    } catch (Exception $e) {}
    try {
        $st = $pdo->prepare("SELECT 1 FROM escala_dados WHERE nome_colaborador = :n AND data_escala = :d AND status NOT LIKE 'plantao%' LIMIT 1");
        $st->execute(['n' => $nome, 'd' => $data]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}

// Situação completa de um dia
function ptStatusDia($pdo, $regra, $nome, $data, $agora = null) {
    $agora = $agora ?: time();
    $hoje = date('Y-m-d', $agora);
    $batidas = ptBatidasDoDia($pdo, $nome, $data);
    $ativas = ptAtivas($batidas);
    $tem = array_column($ativas, 'tipo');
    $faltam = array_values(array_diff(PT_TIPOS, $tem));
    $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
    $sem = ptDiaSemObrigacao($pdo, $nome, $data);
    $alerta = null; $atrasadas = [];

    if ($r && !$sem && $faltam) {
        if ($data < $hoje) {
            $alerta = count($ativas) === 0 ? 'Nenhuma batida registrada neste dia.'
                : 'Batidas incompletas — faltam: ' . implode(', ', array_map(function ($t) { return PT_LABEL[$t]; }, $faltam)) . '.';
        } elseif ($data === $hoje) {
            $min = (int)date('G', $agora) * 60 + (int)date('i', $agora);
            $prog = ['ENTRADA' => ptMin($r['ini']), 'SAIDA_ALMOCO' => ptMin($r['alm_ini']), 'RETORNO_ALMOCO' => ptMin($r['alm_fim']), 'SAIDA' => ptMin($r['fim'])];
            // flexibilidade: a entrada pode variar até ±flex_min; as demais batidas acompanham o desvio real da entrada (jornada preservada)
            $flex = max(0, min(PT_FLEX_MAX, (int)($regra['flex_min'] ?? 0))); $ent = null;
            foreach ($ativas as $b) if ($b['tipo'] === 'ENTRADA') $ent = ptMinDoMomento($b['momento']);
            $desl = $ent !== null ? max(-$flex, min($flex, $ent - $prog['ENTRADA'])) : $flex;
            foreach ($faltam as $t) if ($min > $prog[$t] + (int)$regra['tolerancia'] + ($t === 'ENTRADA' ? $flex : $desl)) $atrasadas[] = $t;
            if ($atrasadas) $alerta = 'Batida pendente: ' . implode(', ', array_map(function ($t) { return PT_LABEL[$t]; }, $atrasadas)) . '.';
        }
    }
    $trab = null; $saldo = null;
    if (!$faltam) {
        $m = []; foreach ($ativas as $b) $m[$b['tipo']] = strtotime($b['momento']);
        $trab = (int)round((($m['SAIDA_ALMOCO'] - $m['ENTRADA']) + ($m['SAIDA'] - $m['RETORNO_ALMOCO'])) / 60);
        if ($r) $saldo = $trab - ((ptMin($r['fim']) - ptMin($r['ini'])) - (ptMin($r['alm_fim']) - ptMin($r['alm_ini'])));
    } elseif ((!$r || $sem) && in_array('ENTRADA', $tem, true) && in_array('SAIDA', $tem, true)) { // dia sem expediente (hora extra): basta entrada e saída
        $m = []; foreach ($ativas as $b) $m[$b['tipo']] = strtotime($b['momento']);
        $trab = (int)round(($m['SAIDA'] - $m['ENTRADA']) / 60);
        if (isset($m['SAIDA_ALMOCO'], $m['RETORNO_ALMOCO'])) $trab -= (int)round(($m['RETORNO_ALMOCO'] - $m['SAIDA_ALMOCO']) / 60);
        $trab = max(0, $trab);
    }
    if ($trab !== null && in_array('EXTRA_ENTRADA', $tem, true) && in_array('EXTRA_SAIDA', $tem, true)) { // período de hora extra incluído à parte
        $x = []; foreach ($ativas as $b) $x[$b['tipo']] = strtotime($b['momento']);
        $trab += max(0, (int)round(($x['EXTRA_SAIDA'] - $x['EXTRA_ENTRADA']) / 60));
    }
    $ad0 = ptAdmissao($pdo, $nome); $preAd = (bool)($ad0 && $data < $ad0); $ferNome = ($sem && !$preAd) ? ptFeriadoNome($pdo, $data, $nome) : null;
    $abonos = []; // abonos/atestados aprovados ou em análise que cobrem o dia (aparecem no ponto do colaborador)
    try {
        $q = $pdo->prepare("SELECT id, tipo, motivo, (arquivo IS NOT NULL) AS tem_anexo, to_char(hora_ini,'HH24:MI') AS hora_ini, to_char(hora_fim,'HH24:MI') AS hora_fim, status FROM ponto_atestados
                            WHERE nome = :n AND status IN ('pendente','aprovada') AND data_ini <= :d AND data_fim >= :d ORDER BY id");
        $q->execute(['n' => $nome, 'd' => $data]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a)
            $abonos[] = ['id' => (int)$a['id'], 'tem_anexo' => (bool)$a['tem_anexo'], 'tipo' => $a['tipo'], 'motivo' => $a['motivo'] ?: ($a['tipo'] === 'dia' ? 'Atestado médico (dia)' : 'Atestado médico (consulta)'),
                         'hora_ini' => $a['hora_ini'], 'hora_fim' => $a['hora_fim'], 'status' => $a['status']];
    } catch (Exception $e) {}
    try { // folga do banco de horas (aprovada ou em análise) aparece no dia como abono
        $q = $pdo->prepare("SELECT id, status FROM ponto_banco_folgas WHERE nome = :n AND data = :d AND status IN ('pendente','aprovada') ORDER BY id");
        $q->execute(['n' => $nome, 'd' => $data]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $a) $abonos[] = ['id' => (int)$a['id'], 'tem_anexo' => false, 'tipo' => 'dia', 'motivo' => 'Folga do banco de horas', 'hora_ini' => null, 'hora_fim' => null, 'status' => $a['status'], 'banco' => true];
    } catch (Exception $e) {}
    return ['data' => $data, 'dow' => (int)date('w', strtotime($data)), 'tem_regra' => (bool)$r, 'sem_obrigacao' => $sem, 'pre_admissao' => $preAd, 'feriado' => $ferNome, 'abonos' => $abonos,
            'batidas' => $batidas, 'faltam' => $faltam, 'proximo' => (ptDiaFds($batidas, !$r || $sem) ? ptProximoTipoFds($batidas) : ptProximoTipo($batidas)), 'fds' => ptDiaFds($batidas, !$r || $sem), 'alerta' => $alerta,
            'sugestoes' => ptSugestoes($regra, $data, $batidas), 'trabalhado_min' => $trab, 'saldo_min' => $saldo,
            'pendentes' => count(array_filter($batidas, function ($b) { return $b['status'] === 'pendente'; })),
            'remocoes_pendentes' => count(array_filter($batidas, function ($b) { return $b['remocao_status'] === 'pendente'; }))];
}

// ---------- folha de ponto (período do dia 15 do mês anterior ao dia 14 do mês de referência) ----------

function ptFolhaRefDe($data) { // 'YYYY-MM-DD' -> 'YYYY-MM' da folha a que o dia pertence
    [$y, $m, $d] = array_map('intval', explode('-', $data));
    if ($d >= PT_DIA_CORTE) { $m++; if ($m > 12) { $m = 1; $y++; } }
    return sprintf('%04d-%02d', $y, $m);
}
function ptFolhaRefValida($ref) { return is_string($ref) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ref) && (int)substr($ref, 0, 4) >= 2000; }
function ptFolhaDeslocar($ref, $delta) {
    [$y, $m] = array_map('intval', explode('-', $ref));
    $t = $y * 12 + ($m - 1) + $delta;
    return sprintf('%04d-%02d', intdiv($t, 12), $t % 12 + 1);
}
function ptFolhaPeriodo($ref) { // [inicio, fim]
    $ant = ptFolhaDeslocar($ref, -1);
    return [$ant . '-' . sprintf('%02d', PT_DIA_CORTE), $ref . '-' . sprintf('%02d', PT_DIA_CORTE - 1)];
}
// A folha anterior à vigente (e a vigente) podem ser ajustadas; mais antigo que isso só com o gestor
function ptJanelaEdicaoMin($hoje) {
    return ptFolhaPeriodo(ptFolhaDeslocar(ptFolhaRefDe($hoje), -1))[0];
}

function ptFolha($pdo, $regra, $nome, $ref, $agora = null, $desde = null) { // $desde: não cobra alertas antes desta data
    $agora = $agora ?: time();
    $hoje = date('Y-m-d', $agora);
    [$ini, $fim] = ptFolhaPeriodo($ref);
    $min = ptJanelaEdicaoMin($hoje);
    $ad = ptAdmissao($pdo, $nome); if ($ad && (!$desde || $ad > $desde)) $desde = $ad;
    $vp = [];
    $q = $pdo->prepare("SELECT id, to_char(data_ref,'YYYY-MM-DD') AS d, vidpol FROM ponto_vidpol WHERE nome = :n AND data_ref BETWEEN :a AND :b ORDER BY id");
    $q->execute(['n' => $nome, 'a' => $ini, 'b' => $fim]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $vp[$r['d']][] = ['id' => (int)$r['id'], 'vidpol' => $r['vidpol']];
    $hj = []; // justificativa da hora extra sem VIDPOL
    try {
        $q = $pdo->prepare("SELECT to_char(data_ref,'YYYY-MM-DD') AS d, justificativa FROM ponto_he_dia WHERE nome = :n AND data_ref BETWEEN :a AND :b AND COALESCE(justificativa,'') <> ''");
        $q->execute(['n' => $nome, 'a' => $ini, 'b' => $fim]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $hj[$r['d']] = $r['justificativa'];
    } catch (Exception $e) {}
    $hs = []; // decisão do gestor sobre a hora extra de cada dia
    try {
        $q = $pdo->prepare("SELECT to_char(data_ref,'YYYY-MM-DD') AS d, status FROM ponto_he_dia WHERE nome = :n AND data_ref BETWEEN :a AND :b");
        $q->execute(['n' => $nome, 'a' => $ini, 'b' => $fim]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $hs[$r['d']] = $r['status'];
    } catch (Exception $e) {}
    $dias = []; $res = ['dias_trabalhados' => 0, 'previsto_min' => 0, 'trabalhado_min' => 0, 'extra_min' => 0, 'pagar_min' => 0, 'saldo_min' => 0, 'dias_pendentes' => 0, 'solicitacoes' => 0, 'extra_aguardando_min' => 0];
    for ($d = $ini; $d <= $fim; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        $s = ptStatusDia($pdo, $regra, $nome, $d, $agora);
        if ($desde && $d < $desde) $s['alerta'] = null;
        $s['futuro'] = $d > $hoje; $s['hoje'] = $d === $hoje;
        $s['editavel'] = $d <= $hoje && $d >= $min;
        $prev = ($s['tem_regra'] && !$s['sem_obrigacao']) ? ptPrevistoMin($regra, $d) : 0;
        $res['previsto_min'] += $prev; // mês inteiro (inclui dias futuros e dias sem batidas)
        if ($prev && isset($s['sugestoes']['SAIDA'])) { // saída sugerida fecha a jornada prevista de 09:00 a partir das demais batidas
            $m = []; foreach (ptAtivas($s['batidas']) as $b) $m[$b['tipo']] = ptMinDoMomento($b['momento']);
            $e = $m['ENTRADA'] ?? ptMin($s['sugestoes']['ENTRADA'] ?? '08:00'); $sa = $m['SAIDA_ALMOCO'] ?? ptMin($s['sugestoes']['SAIDA_ALMOCO'] ?? '12:00');
            $ra = $m['RETORNO_ALMOCO'] ?? ptMin($s['sugestoes']['RETORNO_ALMOCO'] ?? '13:00');
            $s['sugestoes']['SAIDA'] = ptHHMM($ra + max(0, $prev - ($sa - $e)));
        }
        $mm = []; foreach (ptAtivas($s['batidas']) as $b) $mm[$b['tipo']] = strtotime($b['momento']);
        $par = 0; // horas trabalhadas até agora: soma dos períodos já fechados
        if (isset($mm['ENTRADA'], $mm['SAIDA_ALMOCO'])) $par += (int)round(($mm['SAIDA_ALMOCO'] - $mm['ENTRADA']) / 60);
        if (isset($mm['RETORNO_ALMOCO'], $mm['SAIDA'])) $par += (int)round(($mm['SAIDA'] - $mm['RETORNO_ALMOCO']) / 60);
        if (isset($mm['EXTRA_ENTRADA'], $mm['EXTRA_SAIDA'])) $par += max(0, (int)round(($mm['EXTRA_SAIDA'] - $mm['EXTRA_ENTRADA']) / 60));
        if ($s['trabalhado_min'] !== null) $par = $s['trabalhado_min'];
        $s['trab_parcial_min'] = $par; $res['trabalhado_min'] += $par;
        $s['vidpols'] = $vp[$d] ?? []; $s['he_just'] = $hj[$d] ?? '';
        $s['previsto_min'] = $prev; $s['extra_min'] = null; $s['pagar_min'] = null; $s['saldo_min'] = null; $s['he_status'] = null; $s['he_liberado'] = null;
        if ($s['trabalhado_min'] !== null) { // só dias com as 4 batidas entram nas horas
            $dif = $s['trabalhado_min'] - $prev;
            $s['saldo_min'] = $dif;
            $s['extra_min'] = $dif > PT_TOLERANCIA_MIN ? $dif : 0;
            $s['pagar_min'] = $dif < -PT_TOLERANCIA_MIN ? -$dif : 0;
            $res['dias_trabalhados']++;
            $res['saldo_min'] += $dif; $res['pagar_min'] += $s['pagar_min'];
            if ($s['extra_min'] > 0) {
                // he_status: aguardando_batida (batida pendente do gestor) -> sem_info (falta VIDPOL/justificativa) -> em_aprovacao (aguarda o gestor) -> aprovada | recusada | cancelada
                $hst = $hs[$d] ?? null;
                if ($s['pendentes'] > 0 || $s['remocoes_pendentes'] > 0 || $hst === null) $s['he_status'] = 'aguardando_batida';
                elseif ($hst === 'pendente') $s['he_status'] = (!empty($vp[$d]) || ($hj[$d] ?? '') !== '') ? 'em_aprovacao' : 'sem_info';
                else $s['he_status'] = $hst;
                $s['he_liberado'] = in_array($s['he_status'], ['sem_info', 'em_aprovacao', 'aprovada'], true);
                if ($s['he_status'] === 'aprovada') $res['extra_min'] += $s['extra_min']; else $res['extra_aguardando_min'] += $s['extra_min'];
            }
        }
        if ($s['alerta'] && !$s['hoje']) $res['dias_pendentes']++;
        $res['solicitacoes'] += $s['pendentes'] + $s['remocoes_pendentes'];
        $dias[] = $s;
    }
    return ['ref' => $ref, 'inicio' => $ini, 'fim' => $fim, 'anterior' => ptFolhaDeslocar($ref, -1), 'proxima' => ptFolhaDeslocar($ref, 1),
            'atual' => ptFolhaRefDe($hoje), 'ref_min' => ($ad ? ptFolhaRefDe($ad) : null), 'janela_min' => $min, 'dias' => $dias, 'resumo' => $res];
}

// Jornada líquida prevista do dia (minutos) conforme a regra de horário: (fim - início) - almoço. Ex.: seg–qui 08:00–18:00 = 09:00; sexta 08:00–17:00 = 08:00
function ptPrevistoMin($regra, $data) {
    $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
    if (!$r) return 0;
    return max(0, (ptMin($r['fim']) - ptMin($r['ini'])) - (ptMin($r['alm_fim']) - ptMin($r['alm_ini'])));
}
// Hora extra do dia (minutos) conforme jornada prevista e tolerância; 0 se o dia não tem as 4 batidas
function ptExtraDia($pdo, $regra, $nome, $data) {
    $s = ptStatusDia($pdo, $regra, $nome, $data);
    if ($s['trabalhado_min'] === null) return 0;
    $prev = ($s['tem_regra'] && !$s['sem_obrigacao']) ? ptPrevistoMin($regra, $data) : 0;
    $dif = $s['trabalhado_min'] - $prev;
    return $dif > PT_TOLERANCIA_MIN ? $dif : 0;
}

// ---------- hora extra para a Escala mensal ----------
// A Escala (página mensal) lê a tabela ponto_hora_extra: períodos de hora extra batidos no Ponto, com início, fim e VIDPOL.
// Só entram batidas efetivas (válidas/aprovadas) e só chegam à Escala os dias que o gestor aprovou (ponto_he_dia.status = 'aprovada').
// Se os períodos do dia mudarem depois da decisão, o dia volta a aguardar aprovação.
function ptGarantirHoraExtra($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_hora_extra (
        id SERIAL PRIMARY KEY, nome VARCHAR(150) NOT NULL, data_ref DATE NOT NULL,
        hora_inicio VARCHAR(5) NOT NULL, hora_fim VARCHAR(5) NOT NULL, dia_inicio SMALLINT NOT NULL DEFAULT 0,
        minutos INT NOT NULL, vidpols TEXT NOT NULL DEFAULT '', origem VARCHAR(20) NOT NULL DEFAULT 'extra',
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_phe_nome_data ON ponto_hora_extra (nome, data_ref)");
    // a hora extra só vai para a Escala mensal depois que o gestor aprova (uma decisão por colaborador/dia)
    $pdo->exec("ALTER TABLE ponto_hora_extra ADD COLUMN IF NOT EXISTS status VARCHAR(10) NOT NULL DEFAULT 'pendente'");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_he_dia (
        id SERIAL PRIMARY KEY, nome VARCHAR(150) NOT NULL, data_ref DATE NOT NULL, assinatura VARCHAR(40) NOT NULL,
        status VARCHAR(10) NOT NULL DEFAULT 'pendente', decidido_por VARCHAR(100), decidido_em TIMESTAMP, motivo_decisao TEXT,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (nome, data_ref))");
    $pdo->exec("ALTER TABLE ponto_he_dia ADD COLUMN IF NOT EXISTS justificativa TEXT"); // justificativa do colaborador quando a hora extra não tem VIDPOL
}

// Períodos de hora extra de um colaborador no dia: [[ts_inicio, ts_fim, origem], ...]
// 'extra' = período de hora extra incluído (Início/Fim da hora extra); 'sem_expediente' = trabalho em dia sem expediente (folga, fim de semana, feriado);
// 'excedente' = tempo trabalhado além da jornada prevista em dia útil (saída depois do horário)
function ptPeriodosExtra($pdo, $regra, $nome, $data) {
    $m = [];
    foreach (ptBatidasDoDia($pdo, $nome, $data) as $b)
        if (in_array($b['status'], ['valida', 'aprovada'], true)) $m[$b['tipo']] = strtotime($b['momento']);
    $per = [];
    if (isset($m['EXTRA_ENTRADA'], $m['EXTRA_SAIDA']) && $m['EXTRA_SAIDA'] > $m['EXTRA_ENTRADA'])
        $per[] = [$m['EXTRA_ENTRADA'], $m['EXTRA_SAIDA'], 'extra'];
    if (isset($m['ENTRADA'], $m['SAIDA']) && $m['SAIDA'] > $m['ENTRADA']) {
        $r = $regra['dias'][(string)date('w', strtotime($data))] ?? null;
        if (!$r || ptDiaSemObrigacao($pdo, $nome, $data)) {
            if (isset($m['SAIDA_ALMOCO'], $m['RETORNO_ALMOCO']) && $m['ENTRADA'] < $m['SAIDA_ALMOCO'] && $m['SAIDA_ALMOCO'] < $m['RETORNO_ALMOCO'] && $m['RETORNO_ALMOCO'] < $m['SAIDA']) {
                $per[] = [$m['ENTRADA'], $m['SAIDA_ALMOCO'], 'sem_expediente'];
                $per[] = [$m['RETORNO_ALMOCO'], $m['SAIDA'], 'sem_expediente'];
            } else $per[] = [$m['ENTRADA'], $m['SAIDA'], 'sem_expediente'];
        } elseif (isset($m['SAIDA_ALMOCO'], $m['RETORNO_ALMOCO']) && $m['ENTRADA'] < $m['SAIDA_ALMOCO'] && $m['SAIDA_ALMOCO'] < $m['RETORNO_ALMOCO'] && $m['RETORNO_ALMOCO'] < $m['SAIDA']) {
            // dia útil com as 4 batidas: o que passar da jornada prevista (além da tolerância) é hora extra, no fim do expediente (igual à Folha)
            $dif = (int)round((($m['SAIDA_ALMOCO'] - $m['ENTRADA']) + ($m['SAIDA'] - $m['RETORNO_ALMOCO'])) / 60) - ptPrevistoMin($regra, $data);
            if ($dif > PT_TOLERANCIA_MIN) $per[] = [max($m['RETORNO_ALMOCO'], $m['SAIDA'] - $dif * 60), $m['SAIDA'], 'excedente'];
        }
    }
    return $per;
}

// Recalcula e grava os períodos do dia (idempotente: apaga e reinclui)
function ptSincronizarHE($pdo, $regra, $nome, $data) {
    $linhas = [];
    foreach (ptPeriodosExtra($pdo, $regra, $nome, $data) as $p) {
        $min = (int)round(($p[1] - $p[0]) / 60);
        if ($min <= 0 || $min > 1440) continue;
        $hi = date('H:i', $p[0]); $hf = date('H:i', $p[1]);
        if ($hi === $hf) continue;
        $linhas[] = ['hi' => $hi, 'hf' => $hf, 'di' => date('Y-m-d', $p[0]) > $data ? 1 : 0, 'm' => $min, 'o' => $p[2]];
    }
    $del = $pdo->prepare("DELETE FROM ponto_hora_extra WHERE nome = :n AND data_ref = :d");
    if (!$linhas) { // sem hora extra no dia: some também a decisão
        $del->execute(['n' => $nome, 'd' => $data]);
        $pdo->prepare("DELETE FROM ponto_he_dia WHERE nome = :n AND data_ref = :d AND status <> 'cancelada'")->execute(['n' => $nome, 'd' => $data]);
        return;
    }
    $q = $pdo->prepare("SELECT vidpol FROM ponto_vidpol WHERE nome = :n AND data_ref = :d ORDER BY id");
    $q->execute(['n' => $nome, 'd' => $data]);
    $vp = implode(',', $q->fetchAll(PDO::FETCH_COLUMN));
    $sig = md5(implode('|', array_map(function ($l) { return $l['hi'] . '-' . $l['hf'] . '-' . $l['di']; }, $linhas)));
    // decisão do gestor: vale enquanto os períodos do dia forem os mesmos; se mudarem, volta a pendente
    $st = $pdo->prepare("INSERT INTO ponto_he_dia (nome, data_ref, assinatura) VALUES (:n, :d, :s)
        ON CONFLICT (nome, data_ref) DO UPDATE SET assinatura = EXCLUDED.assinatura,
            status = CASE WHEN ponto_he_dia.assinatura = EXCLUDED.assinatura AND ponto_he_dia.status <> 'cancelada' THEN ponto_he_dia.status ELSE 'pendente' END,
            decidido_por = CASE WHEN ponto_he_dia.assinatura = EXCLUDED.assinatura AND ponto_he_dia.status <> 'cancelada' THEN ponto_he_dia.decidido_por ELSE NULL END,
            decidido_em = CASE WHEN ponto_he_dia.assinatura = EXCLUDED.assinatura AND ponto_he_dia.status <> 'cancelada' THEN ponto_he_dia.decidido_em ELSE NULL END,
            motivo_decisao = CASE WHEN ponto_he_dia.assinatura = EXCLUDED.assinatura AND ponto_he_dia.status <> 'cancelada' THEN ponto_he_dia.motivo_decisao ELSE NULL END,
            destino = CASE WHEN ponto_he_dia.assinatura = EXCLUDED.assinatura AND ponto_he_dia.status <> 'cancelada' THEN ponto_he_dia.destino ELSE NULL END
        RETURNING status, destino");
    $st->execute(['n' => $nome, 'd' => $data, 's' => $sig]);
    $rwHE = $st->fetch(PDO::FETCH_ASSOC); $status = (string)($rwHE['status'] ?? '');
    if ($status === 'aprovada' && ($rwHE['destino'] ?? '') === 'banco') $status = 'banco';
    $del->execute(['n' => $nome, 'd' => $data]);
    $ins = $pdo->prepare("INSERT INTO ponto_hora_extra (nome, data_ref, hora_inicio, hora_fim, dia_inicio, minutos, vidpols, origem, status)
                          VALUES (:n, :d, :hi, :hf, :di, :m, :v, :o, :st)");
    foreach ($linhas as $l)
        $ins->execute(['n' => $nome, 'd' => $data, 'hi' => $l['hi'], 'hf' => $l['hf'], 'di' => $l['di'], 'm' => $l['m'], 'v' => $vp, 'o' => $l['o'], 'st' => $status]);
}

// Reprocessa os últimos $dias dias (e remove sobras de dias que não têm mais batidas)
function ptSincronizarJanela($pdo, $dias) {
    $ini = date('Y-m-d', strtotime('-' . (int)$dias . ' days'));
    $st = $pdo->prepare("SELECT DISTINCT nome, to_char(data_ref,'YYYY-MM-DD') AS d FROM ponto_batidas
                         WHERE data_ref >= :i AND status IN ('valida','aprovada') AND tipo IN ('ENTRADA','EXTRA_ENTRADA')");
    $st->execute(['i' => $ini]);
    $ok = []; $regras = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        if (!isset($regras[$r['nome']])) $regras[$r['nome']] = ptRegra($pdo, $r['nome']);
        ptSincronizarHE($pdo, $regras[$r['nome']], $r['nome'], $r['d']);
        $ok[$r['nome'] . '|' . $r['d']] = true;
    }
    $q = $pdo->prepare("SELECT DISTINCT nome, to_char(data_ref,'YYYY-MM-DD') AS d FROM ponto_he_dia WHERE data_ref >= :i
                        UNION SELECT DISTINCT nome, to_char(data_ref,'YYYY-MM-DD') FROM ponto_hora_extra WHERE data_ref >= :i");
    $q->execute(['i' => $ini]);
    $del = $pdo->prepare("DELETE FROM ponto_hora_extra WHERE nome = :n AND data_ref = :d");
    $del2 = $pdo->prepare("DELETE FROM ponto_he_dia WHERE nome = :n AND data_ref = :d AND status <> 'cancelada'");
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r)
        if (empty($ok[$r['nome'] . '|' . $r['d']])) { $del->execute(['n' => $r['nome'], 'd' => $r['d']]); $del2->execute(['n' => $r['nome'], 'd' => $r['d']]); }
}

// No máximo a cada 15 min (e na primeira vez, com todo o histórico de até 400 dias) reprocessa a janela recente
function ptSincronizarJanelaSeNecessario($pdo) {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ponto_he_sync3'");
    $st->execute();
    $v = $st->fetchColumn();
    if ($v !== false && time() - (int)$v < 900) return;
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ponto_he_sync3', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")
        ->execute(['v' => (string)time()]);
    ptSincronizarJanela($pdo, $v === false ? 400 : 45);
}

// Dias alterados na requisição (batida, aprovação, VIDPOL…) são recalculados ao final dela
function ptAgendarSyncHE($nome, $data) {
    if ($nome && $data) $GLOBALS['pt_he_fila'][$nome . '|' . $data] = [$nome, $data];
}
function ptSyncFimDaRequisicao($pdo) {
    try {
        foreach ($GLOBALS['pt_he_fila'] ?? [] as $p) ptSincronizarHE($pdo, ptRegra($pdo, $p[0]), $p[0], $p[1]);
        $GLOBALS['pt_he_fila'] = [];
        ptSincronizarJanelaSeNecessario($pdo);
    } catch (Throwable $e) { error_log('ponto: sincronizacao da hora extra: ' . $e->getMessage()); }
}

// ---------- reconhecimento facial (comparação no servidor; o modelo gera o descritor no navegador) ----------

function ptDescritorValido($d) {
    if (!is_array($d) || count($d) !== 128) return false;
    foreach ($d as $v) if (!is_int($v) && !is_float($v)) return false;
    foreach ($d as $v) if (!is_finite((float)$v) || abs($v) > 5) return false;
    return true;
}
function ptDistancia($a, $b) {
    $s = 0.0;
    for ($i = 0; $i < 128; $i++) { $x = (float)$a[$i] - (float)$b[$i]; $s += $x * $x; }
    return sqrt($s);
}
function ptFotoValida($f) {
    return is_string($f) && strlen($f) <= 200000 && preg_match('#^data:image/jpeg;base64,[A-Za-z0-9+/=]+$#', $f);
}
function ptCoordValida($lat, $lng, $prec) {
    return is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180
        && (!isset($prec) || is_numeric($prec));
}
