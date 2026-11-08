<?php
// ponto_fiscal.php — Portaria MTP 671/2021 (AFD do REP-P e AEJ) e LGPD (expurgo das fotos das batidas).
// Incluído por api_ponto.php. Não imprime nada por si só.
const PT_LGPD_FOTOS_MESES = 12;          // fotos das batidas faciais são apagadas depois deste prazo (o registro da batida continua)
const PT_PTRP_NOME = 'SmartNOC Ponto';   // programa de tratamento de registro de ponto (registro 08 do AEJ)
const PT_PTRP_VERSAO = '1.0';

function ptFiscalGarantir($pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_afd (nsr BIGSERIAL PRIMARY KEY, tipo SMALLINT NOT NULL, chave VARCHAR(120) NOT NULL UNIQUE,
        linha TEXT NOT NULL, hash CHAR(64), data_ref DATE, gravado_em TIMESTAMP NOT NULL DEFAULT now())");
    $col = function ($t, $c) use ($pdo) { $q = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_name = :t AND column_name = :c"); $q->execute(['t' => $t, 'c' => $c]); return (bool)$q->fetchColumn(); };
    if (!$col('funcionarios', 'cpf')) $pdo->exec("ALTER TABLE funcionarios ADD COLUMN IF NOT EXISTS cpf VARCHAR(11)");
    if (!$col('ponto_colaboradores', 'consentimento_em')) $pdo->exec("ALTER TABLE ponto_colaboradores ADD COLUMN IF NOT EXISTS consentimento_em TIMESTAMP");
}

/* ---------- LGPD: expurgo diário das fotos antigas ---------- */
function ptLgpdExpurgo($pdo) {
    $hoje = date('Y-m-d');
    $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ponto_lgpd_expurgo'"); $q->execute();
    if ($q->fetchColumn() === $hoje) return 0;
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ponto_lgpd_expurgo', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => $hoje]);
    $n = $pdo->exec("UPDATE ponto_batidas SET foto = NULL WHERE foto IS NOT NULL AND momento < now() - interval '" . (int)PT_LGPD_FOTOS_MESES . " months'");
    if ($n && function_exists('auditar')) auditar($pdo, 'sistema', 'PONTO', "LGPD: $n foto(s) de batidas com mais de " . PT_LGPD_FOTOS_MESES . ' meses apagadas automaticamente');
    // LGPD_DESLIG_V1: cadastro facial (foto + biometria) de quem foi desativado no ponto ou saiu da Escala
    $b = $pdo->exec("UPDATE ponto_colaboradores p SET descritor = NULL, foto_cadastro = NULL, cadastrado_em = NULL
                     WHERE (p.descritor IS NOT NULL OR p.foto_cadastro IS NOT NULL) AND (NOT p.ativo OR NOT EXISTS (SELECT 1 FROM funcionarios f WHERE f.nome = p.nome))");
    if ($b && function_exists('auditar')) auditar($pdo, 'sistema', 'PONTO', "LGPD: cadastro facial de $b colaborador(es) desativado(s) ou desligado(s) apagado automaticamente");
    return (int)$n;
}

/* ---------- dados fiscais (Configuração → Regra de horário) ---------- */
function ptSoDig($s) { return preg_replace('/\D/', '', (string)$s); }
function ptCpfValido($c) {
    $c = ptSoDig($c); if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
    for ($t = 9; $t < 11; $t++) { $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$c[$i] * (($t + 1) - $i); if ((int)$c[$t] !== ((10 * $s) % 11) % 10) return false; }
    return true;
}
function ptCnpjValido($c) {
    $c = ptSoDig($c); if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
    foreach ([12, 13] as $t) { $p = $t === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2]; $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$c[$i] * $p[$i]; $r = $s % 11; if ((int)$c[$t] !== ($r < 2 ? 0 : 11 - $r)) return false; }
    return true;
}
function ptFiscalCfg($pdo) {
    $cfg = ['cnpj' => defined('PT_EMPRESA_CNPJ') ? ptSoDig(PT_EMPRESA_CNPJ) : '', 'razao' => defined('PT_EMPRESA_NOME') ? PT_EMPRESA_NOME : '', 'local' => '', 'inpi' => '',
            'cpf_resp' => '', 'dev_cnpj' => '', 'dev_razao' => '', 'dev_email' => ''];
    try { $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ponto_fiscal'"); $q->execute(); $v = json_decode((string)$q->fetchColumn(), true);
          if (is_array($v)) foreach ($cfg as $k => $x) if (isset($v[$k])) $cfg[$k] = (string)$v[$k]; } catch (Exception $e) {}
    if ($cfg['dev_cnpj'] === '') $cfg['dev_cnpj'] = $cfg['cnpj'];
    if ($cfg['dev_razao'] === '') $cfg['dev_razao'] = $cfg['razao'];
    return $cfg;
}
function ptFiscalSalvar($pdo, $in) {
    $c = ptFiscalCfg($pdo);
    foreach (['razao', 'local', 'dev_razao', 'dev_email'] as $k) if (isset($in[$k])) $c[$k] = mb_substr(trim((string)$in[$k]), 0, 150);
    foreach (['cnpj', 'inpi', 'cpf_resp', 'dev_cnpj'] as $k) if (isset($in[$k])) $c[$k] = ptSoDig($in[$k]);
    if (!ptCnpjValido($c['cnpj'])) return 'CNPJ do empregador inválido.';
    if ($c['dev_cnpj'] !== '' && !ptCnpjValido($c['dev_cnpj']) && !ptCpfValido($c['dev_cnpj'])) return 'CNPJ/CPF do desenvolvedor inválido.';
    if ($c['cpf_resp'] !== '' && !ptCpfValido($c['cpf_resp'])) return 'CPF do responsável inválido.';
    if (strlen($c['inpi']) > 17) return 'O número de registro no INPI tem no máximo 17 dígitos.';
    if ($c['dev_email'] !== '' && !filter_var($c['dev_email'], FILTER_VALIDATE_EMAIL)) return 'E-mail do desenvolvedor inválido.';
    if (trim($c['razao']) === '') return 'Informe a razão social do empregador.';
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ponto_fiscal', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => json_encode($c)]);
    return null;
}
function ptFiscalSemCpf($pdo) { // colaboradores ativos no ponto sem CPF válido (ficam fora dos arquivos)
    $out = [];
    foreach ($pdo->query("SELECT f.nome, f.cpf FROM funcionarios f JOIN ponto_colaboradores p ON p.nome = f.nome WHERE p.ativo ORDER BY f.nome")->fetchAll(PDO::FETCH_ASSOC) as $r)
        if (!ptCpfValido($r['cpf'] ?? '')) $out[] = $r['nome'];
    return $out;
}

/* ---------- utilitários de leiaute ---------- */
function ptIso($s) { $r = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', (string)$s); return $r === false ? '' : str_replace(["\r", "\n", '|'], ' ', $r); }
function ptUtf($s) { return iconv('ISO-8859-1', 'UTF-8', $s); }
function ptFxA($s, $n) { return str_pad(substr(ptIso($s), 0, $n), $n, ' '); }                       // alfanumérico: à esquerda, completa com espaços
function ptFxN($s, $n) { return str_pad(substr(ptSoDig($s), -$n), $n, '0', STR_PAD_LEFT); }          // numérico: zeros à esquerda
function ptDH($ts) { return date('Y-m-d\TH:i:00O', $ts); }                                          // AAAA-MM-ddThh:mm:00-0300
function ptCrc16($s) { // CRC-16/KERMIT (CCITT-TRUE): "123456789" => 2189
    $crc = 0;
    for ($i = 0, $n = strlen($s); $i < $n; $i++) { $crc ^= ord($s[$i]); for ($b = 0; $b < 8; $b++) $crc = ($crc & 1) ? (($crc >> 1) ^ 0x8408) : ($crc >> 1); }
    return sprintf('%04X', $crc);
}

/* ---------- AFD (REP-P) ---------- */
// Registro imutável de cada evento do REP-P com NSR sequencial: 2 (empresa), 5 (empregado) e 7 (marcação facial, com hash SHA-256 encadeado).
function ptAfdSincronizar($pdo, $cfg) {
    $semCpf = [];
    $pdo->beginTransaction();
    try {
        $pdo->exec("SELECT pg_advisory_xact_lock(7710671)");
        $nsr = function () use ($pdo) { return (int)$pdo->query("SELECT nextval(pg_get_serial_sequence('ponto_afd','nsr'))")->fetchColumn(); };
        $ins = $pdo->prepare("INSERT INTO ponto_afd (nsr, tipo, chave, linha, hash, data_ref, gravado_em) VALUES (:n, :t, :c, :l, :h, :d, :g)");
        $existe = $pdo->prepare("SELECT 1 FROM ponto_afd WHERE chave = :c");
        $cpfResp = $cfg['cpf_resp'] !== '' ? $cfg['cpf_resp'] : '';
        $chEmp = 'emp:' . md5($cfg['cnpj'] . '|' . $cfg['razao'] . '|' . $cfg['local']);
        $existe->execute(['c' => $chEmp]);
        if (!$existe->fetchColumn()) {
            $n = $nsr(); $agora = time();
            $l = ptFxN($n, 9) . '2' . ptDH($agora) . ptFxN($cpfResp, 14) . '1' . ptFxA($cfg['cnpj'], 14) . ptFxN('', 14) . ptFxA($cfg['razao'], 150) . ptFxA($cfg['local'], 100);
            $ins->execute(['n' => $n, 't' => 2, 'c' => $chEmp, 'l' => ptUtf($l . ptCrc16($l)), 'h' => null, 'd' => date('Y-m-d', $agora), 'g' => date('Y-m-d H:i:s', $agora)]);
        }
        $prev = (string)$pdo->query("SELECT hash FROM ponto_afd WHERE tipo = 7 ORDER BY nsr DESC LIMIT 1")->fetchColumn();
        $q = $pdo->query("SELECT b.id, b.nome, to_char(b.momento,'YYYY-MM-DD HH24:MI:SS') AS m, to_char(COALESCE((COALESCE(b.sincronizado_em, b.criado_em) AT TIME ZONE current_setting('TimeZone')) AT TIME ZONE 'America/Sao_Paulo', b.momento),'YYYY-MM-DD HH24:MI:SS') AS g,
                                 b.offline, f.cpf, COALESCE(NULLIF(f.nome_completo, ''), f.nome) AS nc,
                                 (SELECT e.agente FROM ponto_eventos e WHERE e.batida_id = b.id AND e.evento = 'BATIDA_FACIAL' ORDER BY e.id LIMIT 1) AS agente
                          FROM ponto_batidas b JOIN funcionarios f ON f.nome = b.nome
                          LEFT JOIN ponto_afd a ON a.chave = 'bat:' || b.id
                          WHERE b.origem = 'facial' AND a.nsr IS NULL ORDER BY COALESCE(b.sincronizado_em, b.criado_em, b.momento), b.id");
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) {
            $cpf = ptSoDig($b['cpf'] ?? '');
            if (!ptCpfValido($cpf)) { $semCpf[$b['nome']] = true; continue; }
            $chCol = 'col:' . $b['nome'] . ':' . $cpf;
            $existe->execute(['c' => $chCol]);
            if (!$existe->fetchColumn()) { // registro tipo 5: inclusão (ou alteração, se o CPF mudou) do empregado
                $q2 = $pdo->prepare("SELECT 1 FROM ponto_afd WHERE tipo = 5 AND chave LIKE :p"); $q2->execute(['p' => 'col:' . $b['nome'] . ':%']);
                $op = $q2->fetchColumn() ? 'A' : 'I';
                $n = $nsr(); $g = strtotime($b['g']);
                $l = ptFxN($n, 9) . '5' . ptDH($g) . $op . ptFxN($cpf, 12) . ptFxA($b['nc'], 52) . ptFxA('', 4) . ptFxN($cpfResp, 11);
                $ins->execute(['n' => $n, 't' => 5, 'c' => $chCol, 'l' => ptUtf($l . ptCrc16($l)), 'h' => null, 'd' => date('Y-m-d', $g), 'g' => date('Y-m-d H:i:s', $g)]);
            }
            $n = $nsr(); $m = strtotime($b['m']); $g = strtotime($b['g']);
            $base = ptFxN($n, 9) . '7' . ptDH($m) . ptFxN($cpf, 12) . ptDH($g) . (strpos((string)($b['agente'] ?? ''), 'PontoApp/') !== false ? '01' : '02') . (($b['offline'] === true || $b['offline'] === 't' || $b['offline'] === 1 || $b['offline'] === '1') ? '1' : '0');
            $hash = hash('sha256', $base . $prev);
            $ins->execute(['n' => $n, 't' => 7, 'c' => 'bat:' . $b['id'], 'l' => ptUtf($base . $hash), 'h' => $hash, 'd' => date('Y-m-d', $m), 'g' => date('Y-m-d H:i:s', $g)]);
            $prev = $hash;
        }
        $pdo->commit();
    } catch (Exception $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return array_keys($semCpf);
}
function ptAfdGerar($pdo, $ini, $fim) {
    $cfg = ptFiscalCfg($pdo);
    if (!ptCnpjValido($cfg['cnpj'])) throw new Exception('Configure o CNPJ do empregador (Regra de horário → Arquivos fiscais).');
    $semCpf = ptAfdSincronizar($pdo, $cfg);
    $l = ptFxN('0', 9) . '1' . '1' . ptFxA($cfg['cnpj'], 14) . ptFxN('', 14) . ptFxA($cfg['razao'], 150) . ptFxN($cfg['inpi'], 17) . $ini . $fim . ptDH(time()) . '004'
       . (strlen(ptSoDig($cfg['dev_cnpj'])) === 11 ? '2' : '1') . ptFxA($cfg['dev_cnpj'], 14) . ptFxA('', 30);
    $linhas = [$l . ptCrc16($l)];
    $cont = [2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0, 7 => 0];
    $q = $pdo->prepare("SELECT tipo, linha FROM ponto_afd WHERE (tipo = 7 AND data_ref BETWEEN :i AND :f)
                        OR (tipo IN (2, 5) AND (data_ref <= :f2 OR nsr < COALESCE((SELECT max(nsr) FROM ponto_afd WHERE tipo = 7 AND data_ref BETWEEN :i3 AND :f3), 0))) ORDER BY nsr");
    $q->execute(['i' => $ini, 'f' => $fim, 'f2' => $fim, 'i3' => $ini, 'f3' => $fim]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) { $linhas[] = ptIso($r['linha']); $cont[(int)$r['tipo']]++; }
    $linhas[] = '999999999' . ptFxN($cont[2], 9) . ptFxN($cont[3], 9) . ptFxN($cont[4], 9) . ptFxN($cont[5], 9) . ptFxN($cont[6], 9) . ptFxN($cont[7], 9) . '9';
    $linhas[] = str_pad('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S', 100, ' ');
    return ['nome' => 'AFD' . ptFxN($cfg['inpi'], 17) . ptFxN($cfg['cnpj'], 14) . 'REP_P.txt', 'conteudo' => implode("\r\n", $linhas) . "\r\n",
            'marcacoes' => $cont[7], 'sem_cpf' => $semCpf];
}

/* ---------- AEJ ---------- */
function ptAejGerar($pdo, $ini, $fim) {
    $cfg = ptFiscalCfg($pdo);
    if (!ptCnpjValido($cfg['cnpj'])) throw new Exception('Configure o CNPJ do empregador (Regra de horário → Arquivos fiscais).');
    $L = []; $cont = array_fill(1, 8, 0);
    $add = function ($tipo, $campos) use (&$L, &$cont) { $L[] = implode('|', array_map('ptIso', $campos)); $cont[(int)$tipo]++; };
    $add(1, ['01', '1', ptSoDig($cfg['cnpj']), '', '', mb_substr($cfg['razao'], 0, 150), $ini, $fim, ptDH(time()), '002']);
    $add(2, ['02', '1', '3', ptFxN($cfg['inpi'], 17)]);
    $colabs = [];
    foreach ($pdo->query("SELECT f.nome, f.cpf, COALESCE(NULLIF(f.nome_completo, ''), f.nome) AS nc FROM funcionarios f JOIN ponto_colaboradores p ON p.nome = f.nome ORDER BY f.nome")->fetchAll(PDO::FETCH_ASSOC) as $c)
        if (ptCpfValido($c['cpf'] ?? '')) $colabs[] = $c;
    $vid = 0; $horarios = []; $marc = []; $aus = []; $semCpf = ptFiscalSemCpf($pdo);
    $codDia = function ($r) { return 'H' . str_replace(':', '', $r['ini'] . $r['alm_ini'] . $r['alm_fim'] . $r['fim']); };
    $hoje = date('Y-m-d');
    foreach ($colabs as $c) {
        $vid++; $nome = $c['nome'];
        $add(3, ['03', (string)$vid, ptSoDig($c['cpf']), mb_substr($c['nc'], 0, 150)]);
        $regra = ptRegra($pdo, $nome); $codPadrao = null;
        foreach ($regra['dias'] as $w => $r) if ($r) {
            $k = $codDia($r); $horarios[$k] = [max(0, (ptMin($r['fim']) - ptMin($r['ini'])) - (ptMin($r['alm_fim']) - ptMin($r['alm_ini']))), $r];
            if ($codPadrao === null) $codPadrao = $k;
        }
        // marcações: ativas (E/S) e faciais desconsideradas (D)
        $q = $pdo->prepare("SELECT to_char(data_ref,'YYYY-MM-DD') AS d, tipo, to_char(momento,'YYYY-MM-DD HH24:MI:SS') AS m, origem, status, justificativa, remocao_justificativa, motivo_decisao
                            FROM ponto_batidas WHERE nome = :n AND data_ref BETWEEN :i AND :f AND (status IN ('valida','aprovada') OR (origem = 'facial' AND status = 'removida')) ORDER BY data_ref, momento, id");
        $q->execute(['n' => $nome, 'i' => $ini, 'f' => $fim]);
        $porDia = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $b) $porDia[$b['d']][] = $b;
        foreach ($porDia as $d => $lista) {
            $r = $regra['dias'][(string)date('w', strtotime($d))] ?? null; $cod = $r ? $codDia($r) : $codPadrao;
            $i = 0;
            foreach ($lista as $b) {
                $ts = strtotime($b['m']);
                if ($b['status'] === 'removida') {
                    $marc[] = ['05', (string)$vid, ptDH($ts), '1', 'D', '001', 'O', '', mb_substr($b['remocao_justificativa'] ?: ($b['motivo_decisao'] ?: 'Marcação desconsiderada'), 0, 150)];
                    continue;
                }
                $tp = $i % 2 === 0 ? 'E' : 'S'; $seq = sprintf('%03d', intdiv($i, 2) + 1); $i++;
                $fonte = $b['origem'] === 'facial' ? 'O' : ($b['origem'] === 'sistema' ? 'P' : 'I');
                $marc[] = ['05', (string)$vid, ptDH($ts), $fonte === 'O' ? '1' : '', $tp, $seq, $fonte, ($tp === 'E' && $seq === '001') ? (string)$cod : '',
                           $fonte === 'I' ? mb_substr($b['justificativa'] ?: 'Inclusão aprovada pelo gestor', 0, 150) : ''];
            }
        }
        // ausências não justificadas e banco de horas
        for ($ref = ptFolhaRefDe($ini); $ref <= ptFolhaRefDe($fim); $ref = ptFolhaDeslocar($ref, 1)) {
            $f = ptFolha($pdo, $regra, $nome, $ref);
            foreach ($f['dias'] as $dd) {
                if ($dd['data'] < $ini || $dd['data'] > $fim || $dd['data'] >= $hoje) continue;
                $abono = false; foreach ($dd['abonos'] ?? [] as $a) if ($a['tipo'] === 'dia' && $a['status'] === 'aprovada') $abono = true;
                if ($dd['tem_regra'] && !$dd['sem_obrigacao'] && !$abono && !ptAtivas($dd['batidas'])) $aus[] = ['07', (string)$vid, '2', $dd['data'], '', ''];
            }
        }
        if (function_exists('ptBancoHoras')) {
            $bh = ptBancoHoras($pdo, $nome);
            if (!empty($bh['ativo'])) foreach (array_reverse($bh['movimentos'] ?? []) as $mv) {
                $dm = $mv['folga'] ?? $mv['data'];
                if ($dm < $ini || $dm > $fim || !in_array($mv['tipo'], ['credito', 'hora_a_pagar', 'folga'], true)) continue;
                $aus[] = ['07', (string)$vid, '3', $dm, (string)abs((int)$mv['min']), $mv['tipo'] === 'credito' ? '1' : '2'];
            }
        }
    }
    foreach ($horarios as $k => $h) $add(4, ['04', $k, (string)$h[0], str_replace(':', '', $h[1]['ini']), str_replace(':', '', $h[1]['alm_ini']), str_replace(':', '', $h[1]['alm_fim']), str_replace(':', '', $h[1]['fim'])]);
    foreach ($marc as $m) $add(5, $m);
    foreach ($aus as $a) $add(7, $a);
    $add(8, ['08', PT_PTRP_NOME, PT_PTRP_VERSAO, strlen(ptSoDig($cfg['dev_cnpj'])) === 11 ? '2' : '1', ptSoDig($cfg['dev_cnpj']), mb_substr($cfg['dev_razao'], 0, 150), mb_substr($cfg['dev_email'], 0, 50)]);
    $t = ['99']; for ($i = 1; $i <= 8; $i++) $t[] = (string)$cont[$i];
    $L[] = implode('|', $t);
    $L[] = str_pad('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S', 100, ' ');
    return ['nome' => 'AEJ_' . ptSoDig($cfg['cnpj']) . '_' . str_replace('-', '', $ini) . '_' . str_replace('-', '', $fim) . '.txt', 'conteudo' => implode("\r\n", $L) . "\r\n",
            'vinculos' => $vid, 'sem_cpf' => $semCpf];
}
