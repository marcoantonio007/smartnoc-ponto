<?php
// ponto_email.php — opções do ponto, exportação de registros e e-mails da folha (regularização + relatório de fim de folha).
// Depende de ponto_lib.php (e do cliente SMTP smtp_mail.php, que usa a configuração salva na Escala).
require_once __DIR__ . '/smtp_mail.php';

const PT_URL_PADRAO = 'https://ponto.smartnoc.com.br';
const PT_EMAIL_HORA_MIN = 7;       // o relatório automático só sai depois das 07:00 do dia 15
const PT_EMAIL_JANELA_DIAS = 7;    // e só até 7 dias depois do fechamento (evita mandar folha velha ao ligar a opção)
const PT_EMAIL_TENTATIVAS = 3;     // falhas por colaborador/folha antes de desistir
const PT_EMAIL_LOTE_WEB = 3;       // se não der para abrir um processo em segundo plano, o gatilho do site manda no máximo 3 e-mails por rodada

function ptHoras($min) { $s = $min < 0 ? '-' : ''; $min = abs((int)round($min)); return $s . sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function ptUrl() { return rtrim(getenv('PONTO_URL') ?: PT_URL_PADRAO, '/'); }

function ptEmailGarantir($pdo) {
    if ($pdo->query("SELECT to_regclass('ponto_email_envios') IS NOT NULL AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name = 'ponto_colaboradores' AND column_name = 'email')")->fetchColumn()) return;
    $pdo->exec("ALTER TABLE ponto_colaboradores ADD COLUMN IF NOT EXISTS email VARCHAR(200)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS ponto_email_envios (
        id SERIAL PRIMARY KEY, nome VARCHAR(150) NOT NULL, ref VARCHAR(7) NOT NULL, tipo VARCHAR(12) NOT NULL, email VARCHAR(200),
        ok BOOLEAN NOT NULL, erro TEXT, qtd_datas INT, enviado_por VARCHAR(100), criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_pee_nome_ref ON ponto_email_envios (nome, ref, tipo)");
    $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_pee_fim ON ponto_email_envios (nome, ref) WHERE tipo = 'fim_folha' AND ok");
}

/* ---------- opções (tela Regra de horário) ---------- */
function ptOpcao($pdo, $chave, $padrao = true) {
    $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = :c"); $st->execute(['c' => $chave]);
    $v = $st->fetchColumn();
    return $v === false ? $padrao : $v === '1';
}
function ptOpcaoSalvar($pdo, $chave, $val) {
    $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (:c, :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")
        ->execute(['c' => $chave, 'v' => $val ? '1' : '0']);
}
function ptGestorEdita($pdo) { return ptOpcao($pdo, 'ponto_gestor_edita', true); }
function ptEmailAuto($pdo) { return ptOpcao($pdo, 'ponto_email_auto', true); }

/* ---------- exportação de registros ---------- */
function ptRegistrosPeriodo($pdo, $ini, $fim, $filtro) { // mesma consulta da aba Registros
    $st = $pdo->prepare("SELECT DISTINCT nome FROM ponto_colaboradores WHERE ativo AND (cadastrado_em IS NOT NULL) AND (:f = '' OR nome ILIKE :fl) ORDER BY nome");
    $st->execute(['f' => $filtro, 'fl' => '%' . $filtro . '%']);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $n) {
        $regraN = ptRegra($pdo, $n); $ad = ptAdmissao($pdo, $n);
        for ($d = $ini; $d <= $fim; $d = date('Y-m-d', strtotime("$d +1 day"))) {
            if ($ad && $d < $ad) continue; // só a partir da admissão
            $s = ptStatusDia($pdo, $regraN, $n, $d);
            if ($s['batidas'] || $s['alerta']) { $s['nome'] = $n; $out[] = $s; }
        }
    }
    return $out;
}
function ptCsvCelula($v) {
    $v = (string)$v;
    if ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) $v = "'" . $v; // evita fórmula ao abrir no Excel
    return '"' . str_replace('"', '""', $v) . '"';
}
function ptBrData($d) { return substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4); }
const PT_DIAS_SEM = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
function ptExportarCsv($dias, $url) {
    $o = "\xEF\xBB\xBF"; // BOM: o Excel abre em UTF-8
    $o .= implode(';', array_map('ptCsvCelula', ['Colaborador', 'Data', 'Dia', 'Registro', 'Horário', 'Origem', 'Situação', 'Justificativa', 'Observação do gestor', 'Latitude', 'Longitude', 'Precisão (m)', 'Similaridade facial', 'Foto da batida (link)', 'Alerta do dia'])) . "\r\n";
    foreach ($dias as $d) {
        $base = [$d['nome'], ptBrData($d['data']), PT_DIAS_SEM[$d['dow']]];
        if (!$d['batidas']) { $o .= implode(';', array_map('ptCsvCelula', array_merge($base, ['—', '', '', '', '', '', '', '', '', '', '', $d['alerta'] ?? '']))) . "\r\n"; continue; }
        foreach ($d['batidas'] as $b) {
            $o .= implode(';', array_map('ptCsvCelula', array_merge($base, [PT_LABEL[$b['tipo']] ?? $b['tipo'], substr($b['momento'], 11, 5), $b['origem'],
                $b['status'] . ($b['remocao_status'] === 'pendente' ? ' (remoção pendente)' : ''), $b['justificativa'] ?? '', $b['motivo_decisao'] ?? '',
                $b['lat'] !== null ? str_replace('.', ',', (string)$b['lat']) : '', $b['lng'] !== null ? str_replace('.', ',', (string)$b['lng']) : '',
                $b['precisao'] !== null ? (string)round($b['precisao']) : '', $b['distancia'] !== null ? str_replace('.', ',', number_format((float)$b['distancia'], 2, '.', '')) : '',
                $b['tem_foto'] ? $url . '/api_ponto.php?action=ponto_admin_foto_img&id=' . (int)$b['id'] : '', $d['alerta'] ?? ''])) ) . "\r\n";
        }
    }
    return $o;
}
function ptXlsxLetra($i) { $s = ''; $i++; while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = intdiv($i - $m - 1, 26); } return $s; }
function ptXlsxLinhas($dias, $url) { // mesmas colunas do CSV; números (lat/lng/precisão/similaridade) como número
    $l = [['Colaborador', 'Data', 'Dia', 'Registro', 'Horário', 'Origem', 'Situação', 'Justificativa', 'Observação do gestor', 'Latitude', 'Longitude', 'Precisão (m)', 'Similaridade facial', 'Foto da batida (link)', 'Alerta do dia']];
    foreach ($dias as $d) {
        $base = [$d['nome'], ptBrData($d['data']), PT_DIAS_SEM[$d['dow']]];
        if (!$d['batidas']) { $l[] = array_merge($base, ['—', '', '', '', '', '', '', '', '', '', '', $d['alerta'] ?? '']); continue; }
        foreach ($d['batidas'] as $b) {
            $l[] = array_merge($base, [PT_LABEL[$b['tipo']] ?? $b['tipo'], substr($b['momento'], 11, 5), $b['origem'],
                $b['status'] . ($b['remocao_status'] === 'pendente' ? ' (remoção pendente)' : ''), $b['justificativa'] ?? '', $b['motivo_decisao'] ?? '',
                $b['lat'] !== null ? (float)$b['lat'] : '', $b['lng'] !== null ? (float)$b['lng'] : '', $b['precisao'] !== null ? (int)round($b['precisao']) : '',
                $b['distancia'] !== null ? round((float)$b['distancia'], 2) : '',
                $b['tem_foto'] ? $url . '/api_ponto.php?action=ponto_admin_foto_img&id=' . (int)$b['id'] : '', $d['alerta'] ?? '']);
        }
    }
    return $l;
}
function ptZipStore($arqs) { // ZIP sem compressão (método 0), sem depender da extensão zip do PHP
    $dados = ''; $central = ''; $n = 0; $hora = (int)date('G') << 11 | (int)date('i') << 5 | (int)((int)date('s') / 2); $data = ((int)date('Y') - 1980) << 9 | (int)date('n') << 5 | (int)date('j');
    foreach ($arqs as $nome => $conteudo) {
        $crc = pack('V', crc32($conteudo)); $tam = pack('V', strlen($conteudo)); $off = pack('V', strlen($dados));
        $dados .= "PK\x03\x04" . pack('v', 20) . pack('v', 0x0800) . pack('v', 0) . pack('v', $hora) . pack('v', $data) . $crc . $tam . $tam . pack('v', strlen($nome)) . pack('v', 0) . $nome . $conteudo;
        $central .= "PK\x01\x02" . pack('v', 20) . pack('v', 20) . pack('v', 0x0800) . pack('v', 0) . pack('v', $hora) . pack('v', $data) . $crc . $tam . $tam . pack('v', strlen($nome)) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('V', 0) . $off . $nome;
        $n++;
    }
    return $dados . $central . "PK\x05\x06" . pack('v', 0) . pack('v', 0) . pack('v', $n) . pack('v', $n) . pack('V', strlen($central)) . pack('V', strlen($dados)) . pack('v', 0);
}
function ptExportarXlsx($dias, $url) { // planilha .xlsx (Excel / LibreOffice / Google Planilhas)
    $x = fn($s) => htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$s), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $linhas = ptXlsxLinhas($dias, $url); $nc = count($linhas[0]); $nl = count($linhas);
    $larg = [28, 11, 10, 22, 9, 12, 22, 34, 30, 11, 11, 11, 12, 48, 40];
    $cols = ''; foreach ($larg as $i => $w) $cols .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
    $sd = '';
    foreach ($linhas as $r => $lin) {
        $sd .= '<row r="' . ($r + 1) . '">';
        foreach ($lin as $c => $v) {
            if ($v === '' || $v === null) continue;
            $ref = ptXlsxLetra($c) . ($r + 1); $st = $r === 0 ? ' s="1"' : '';
            if (is_int($v) || is_float($v)) $sd .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>';
            else $sd .= '<c r="' . $ref . '"' . $st . ' t="inlineStr"><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
        }
        $sd .= '</row>';
    }
    $ult = ptXlsxLetra($nc - 1) . $nl;
    $e = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    return ptZipStore([
        '[Content_Types].xml' => $e . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
        '_rels/.rels' => $e . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml' => $e . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Registros" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => $e . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml' => $e . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
        'xl/worksheets/sheet1.xml' => $e . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>' . $cols . '</cols><sheetData>' . $sd . '</sheetData><autoFilter ref="A1:' . $ult . '"/></worksheet>',
    ]);
}
class PtRelPdf { // PDF de várias páginas (A4 retrato) para o relatório de registros; fotos JPEG embutidas sem recompressão
    public $W = 595.28, $H = 841.89; private $pg = []; private $i = -1; private $imgs = []; private $uso = [];
    function nova() { $this->pg[] = ''; $this->uso[] = []; $this->i = count($this->pg) - 1; return $this->i; }
    private function cor($c) { return sprintf('%.3f %.3f %.3f', $c[0], $c[1], $c[2]); }
    function texto($x, $y, $s, $size, $bold = false, $cor = [0, 0, 0], $ital = false, $align = 'l') {
        $w = PtPdf::larg($s, $size, $bold);
        if ($align === 'r') $x -= $w; elseif ($align === 'c') $x -= $w / 2;
        $e = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], PtPdf::cp($s));
        $this->pg[$this->i] .= sprintf("BT /%s %.2f Tf %s rg %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : ($ital ? 'F3' : 'F1'), $size, $this->cor($cor), $x, $this->H - $y, $e);
    }
    function ret($x, $y, $w, $h, $cor) { $this->pg[$this->i] .= sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", $this->cor($cor), $x, $this->H - $y - $h, $w, $h); }
    function borda($x, $y, $w, $h, $cor, $esp = 0.5) { $this->pg[$this->i] .= sprintf("%s RG %.2f w %.2f %.2f %.2f %.2f re S\n", $this->cor($cor), $esp, $x, $this->H - $y - $h, $w, $h); }
    function linha($x1, $y1, $x2, $y2, $cor, $esp = 0.8) { $this->pg[$this->i] .= sprintf("%s RG %.2f w %.2f %.2f m %.2f %.2f l S\n", $this->cor($cor), $esp, $x1, $this->H - $y1, $x2, $this->H - $y2); }
    function addImg($bin) { // só JPEG (as fotos da batida já são JPEG); devolve [id, largura, altura] ou null
        $inf = @getimagesizefromstring($bin);
        if (!$inf || $inf[2] !== IMAGETYPE_JPEG || !in_array($inf['channels'] ?? 3, [1, 3], true)) return null;
        $this->imgs[] = ['w' => $inf[0], 'h' => $inf[1], 'cs' => ($inf['channels'] ?? 3) == 1 ? '/DeviceGray' : '/DeviceRGB', 'd' => $bin];
        return [count($this->imgs) - 1, $inf[0], $inf[1]];
    }
    function foto($id, $x, $y, $w, $h) { $this->uso[$this->i][$id] = true; $this->pg[$this->i] .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im%d Do Q\n", $w, $h, $x, $this->H - $y - $h, $id); }
    static function quebra($s, $size, $maxw, $bold = false, $maxLin = 99) {
        $lin = []; $at = '';
        foreach (preg_split('/\s+/u', trim((string)$s)) as $p) {
            if ($p === '') continue;
            while (PtPdf::larg($p, $size, $bold) > $maxw) { // palavra maior que a largura: corta
                $k = mb_strlen($p); while ($k > 1 && PtPdf::larg(mb_substr($p, 0, $k), $size, $bold) > $maxw) $k--;
                if ($at !== '') { $lin[] = $at; $at = ''; }
                $lin[] = mb_substr($p, 0, $k); $p = mb_substr($p, $k);
            }
            if ($p === '') continue;
            $t = $at === '' ? $p : $at . ' ' . $p;
            if (PtPdf::larg($t, $size, $bold) <= $maxw) $at = $t; else { $lin[] = $at; $at = $p; }
        }
        if ($at !== '') $lin[] = $at;
        if (count($lin) > $maxLin) {
            $lin = array_slice($lin, 0, $maxLin); $u = rtrim($lin[$maxLin - 1]) . '…';
            while (mb_strlen($u) > 2 && PtPdf::larg($u, $size, $bold) > $maxw) $u = mb_substr($u, 0, -2) . '…';
            $lin[$maxLin - 1] = $u;
        }
        return $lin;
    }
    function saida($titulo, $rodape) {
        $np = count($this->pg);
        for ($k = 0; $k < $np; $k++) {
            $this->i = $k;
            $this->texto(28, $this->H - 18, $rodape, 7, false, [0.45, 0.45, 0.45]);
            $this->texto($this->W - 28, $this->H - 18, 'Página ' . ($k + 1) . ' de ' . $np, 7, false, [0.45, 0.45, 0.45], false, 'r');
        }
        $gz = function_exists('gzcompress'); $ni = count($this->imgs); $b = 7 + $ni; $o = [];
        $o[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = ''; for ($k = 0; $k < $np; $k++) $kids .= ($b + 2 * $k) . ' 0 R ';
        $o[2] = '<< /Type /Pages /Kids [' . trim($kids) . '] /Count ' . $np . ' >>';
        $o[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $o[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $o[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Oblique /Encoding /WinAnsiEncoding >>';
        $o[6] = '<< /Title (' . str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], PtPdf::cp($titulo)) . ') /Producer (SmartNOC Ponto) >>';
        foreach ($this->imgs as $n => $im) $o[7 + $n] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $im['w'], $im['h'], $im['cs'], strlen($im['d'])) . $im['d'] . "\nendstream";
        for ($k = 0; $k < $np; $k++) {
            $xo = ''; foreach (array_keys($this->uso[$k]) as $id) $xo .= "/Im$id " . (7 + $id) . ' 0 R ';
            $cont = $gz ? gzcompress($this->pg[$k], 6) : $this->pg[$k];
            $o[$b + 2 * $k] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >>' . ($xo !== '' ? ' /XObject << ' . $xo . '>>' : '') . ' >> >>', $this->W, $this->H, $b + 2 * $k + 1);
            $o[$b + 2 * $k + 1] = '<< /Length ' . strlen($cont) . ($gz ? ' /Filter /FlateDecode' : '') . " >>\nstream\n" . $cont . "\nendstream";
        }
        $pdf = "%PDF-1.4\n"; $off = [];
        foreach ($o as $n => $corpo) { $off[$n] = strlen($pdf); $pdf .= "$n 0 obj\n$corpo\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($o) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $f) $pdf .= sprintf("%010d 00000 n \n", $f);
        return $pdf . "trailer\n<< /Size " . (count($o) + 1) . " /Root 1 0 R /Info 6 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}
function ptExportarPdf($pdo, $dias, $ini, $fim, $filtro, $por, $fotos = false, $limiteFotos = 500) { // PDF real (download), com ou sem a foto de cada batida facial
    require_once __DIR__ . '/ponto_pdf.php';
    $pdf = new PtRelPdf(); $M = 28; $uw = $pdf->W - 2 * $M; $gap = 6; $cols = 4; $cw = ($uw - $gap * ($cols - 1)) / $cols; $bw = $cw - 10; $topo = 62; $base = $pdf->H - 34;
    $AZ = [0.114, 0.306, 0.847]; $CZ = [0.4, 0.4, 0.4]; $VM = [0.72, 0.1, 0.1]; $PT = [0, 0, 0];
    $sub = 'Período: ' . ptBrData($ini) . ' a ' . ptBrData($fim) . ($filtro !== '' ? ' · Colaborador: ' . $filtro : ' · Todos os colaboradores') . ($fotos ? ' · com fotos' : '');
    $y = 0; $nomeAt = null; $nFotos = 0; $omitidas = 0;
    $stF = $fotos ? $pdo->prepare("SELECT foto FROM ponto_batidas WHERE id = :id") : null;
    $nova = function () use ($pdf, $M, $uw, $AZ, $CZ, $sub, $topo, &$y) {
        $pdf->nova(); $pdf->texto($M, 28, 'SmartNOC Ponto — Registros de batidas', 12, true); $pdf->texto($M, 41, $sub, 7.5, false, $CZ); $pdf->linha($M, 48, $M + $uw, 48, $AZ, 1.2); $y = $topo;
    };
    $cartao = function ($b) use ($pdf, $fotos, $stF, $bw, &$nFotos, &$omitidas, $limiteFotos, $CZ) {
        $off = in_array($b['status'], ['recusada', 'removida', 'cancelada'], true);
        $sit = $b['status'] . ($b['remocao_status'] === 'pendente' ? ' · remoção pendente' : '');
        $L = []; $img = null;
        if ($fotos && $b['tem_foto']) {
            if ($nFotos < $limiteFotos) { $stF->execute(['id' => $b['id']]); $f = (string)$stF->fetchColumn(); if (preg_match('#^data:image/jpeg;base64,(.+)$#s', $f, $m)) { $img = $pdf->addImg(base64_decode($m[1])); if ($img) $nFotos++; } }
            else $omitidas++;
        }
        // FOTO_MINI_V1: a foto vira uma miniatura no canto superior direito do cartão; o texto ocupa o restante
        $PS = 46; $esq = $bw - $PS - 4; $ty = 5; $seg = [];
        $seg[] = [PT_LABEL[$b['tipo']] ?? $b['tipo'], 6.8, true, [0.27, 0.27, 0.27], false, 2];
        $seg[] = [substr($b['momento'], 11, 5), 15, true, [0, 0, 0], false, 1];
        $seg[] = [$b['origem'] . ' · ' . $sit, 6.3, false, $CZ, false, 2];
        if ($b['distancia'] !== null) $seg[] = ['similaridade ' . number_format((float)$b['distancia'], 2), 6.3, false, $CZ, false, 1];
        if ($b['lat'] !== null) { $seg[] = ['GPS (' . round($b['lat'], 5) . ',', 6.3, false, $CZ, false, 1]; $seg[] = [round($b['lng'], 5) . ') (±' . (int)round($b['precisao'] ?? 0) . ' m)', 6.3, false, $CZ, false, 1]; }
        if (!empty($b['justificativa'])) $seg[] = ['“' . $b['justificativa'] . '”', 6.3, false, $CZ, true, 3];
        foreach ($seg as $s) {
            $mw = ($img && $ty < 5 + $PS) ? $esq : $bw;
            foreach (PtRelPdf::quebra($s[0], $s[1], $mw, $s[2], $s[5]) as $t) { $L[] = [$t, $s[1], $s[2], $s[3], $s[4]]; $ty += $s[1] + 2.4; }
        }
        $h = max($ty, $img ? 5 + $PS : 0) + 3;
        return ['h' => $h, 'L' => $L, 'img' => $img, 'off' => $off];
    };
    foreach ($dias as $d) {
        $cards = array_map($cartao, $d['batidas']); $rows = array_chunk($cards, $cols);
        $rh = array_map(fn($r) => max(array_column($r, 'h')), $rows);
        $alL = $d['alerta'] ? PtRelPdf::quebra('Atenção: ' . $d['alerta'], 7.5, $uw - 8, true) : [];
        $novoNome = $nomeAt !== $d['nome'];
        $need = ($novoNome ? 26 : 0) + 18 + count($alL) * 10 + ($rh ? $rh[0] : 0) + 6;
        if ($y === 0 || $y + $need > $base) $nova();
        if ($novoNome) { $nomeAt = $d['nome']; $pdf->texto($M, $y + 11, mb_strtoupper($d['nome']), 11, true); $pdf->linha($M, $y + 16, $M + $uw, $y + 16, $AZ, 1.2); $y += 24; }
        $cab = function ($cont) use ($pdf, $d, $M, $uw, &$y) {
            $pdf->ret($M, $y, $uw, 14, [0.93, 0.95, 0.98]);
            $pdf->texto($M + 4, $y + 10, PT_DIAS_SEM[$d['dow']] . ' ' . ptBrData($d['data']) . ($cont ? ' (continuação)' : ''), 8.5, true);
            if ($d['trabalhado_min'] !== null) $pdf->texto($M + $uw - 4, $y + 10, 'Trabalhado ' . ptHoras($d['trabalhado_min']), 8, false, [0.3, 0.3, 0.3], false, 'r');
            $y += 17;
        };
        $cab(false);
        foreach ($alL as $t) { $pdf->texto($M + 4, $y + 7, $t, 7.5, true, $VM); $y += 10; }
        foreach ($rows as $ri => $r) {
            if ($y + $rh[$ri] > $base) { $nova(); $cab(true); }
            foreach ($r as $ci => $c) {
                $x = $M + $ci * ($cw + $gap);
                if ($c['off']) $pdf->ret($x, $y, $cw, $rh[$ri], [0.95, 0.95, 0.95]);
                $pdf->borda($x, $y, $cw, $rh[$ri], [0.82, 0.84, 0.88]);
                $ty = $y + 5;
                if ($c['img']) { // miniatura (máx. 46×46) no canto superior direito
                    [$id, $iw, $ih] = $c['img']; $s = min(46 / $iw, 46 / $ih); $w = $iw * $s; $h = $ih * $s;
                    $pdf->foto($id, $x + $cw - 5 - 46 + (46 - $w) / 2, $ty + (46 - $h) / 2, $w, $h);
                }
                foreach ($c['L'] as $l) { $pdf->texto($x + 5, $ty + $l[1] * 0.85, $l[0], $l[1], $l[2], $c['off'] ? [0.55, 0.55, 0.55] : $l[3], $l[4]); $ty += $l[1] + 2.4; }
            }
            $y += $rh[$ri] + $gap;
        }
        $y += 4;
    }
    if (!$dias) { $nova(); $pdf->texto($M, $y + 10, 'Sem registros no período.', 10); }
    if ($omitidas) { if ($y + 24 > $base) $nova(); $pdf->texto($M, $y + 10, 'Por limite de tamanho, ' . $omitidas . ' foto(s) não foram incluídas. Reduza o período ou filtre por colaborador.', 8, true, $VM); }
    return $pdf->saida('Registros de ponto ' . ptBrData($ini) . ' a ' . ptBrData($fim), 'Gerado em ' . date('d/m/Y H:i') . ' por ' . $por);
}
function ptExportarHtml($pdo, $dias, $ini, $fim, $filtro, $por, $limiteFotos = 500, $fotos = true, $auto = false) { // relatório imprimível (Salvar como PDF) com a foto de cada batida facial
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $stF = $pdo->prepare("SELECT foto FROM ponto_batidas WHERE id = :id");
    $nFotos = 0; $omitidas = 0; $corpo = ''; $atual = null;
    foreach ($dias as $d) {
        if ($atual !== $d['nome']) { if ($atual !== null) $corpo .= '</section>'; $atual = $d['nome']; $corpo .= '<section><h2>' . $h($d['nome']) . '</h2>'; }
        $corpo .= '<div class="dia' . ($d['alerta'] ? ' al' : '') . '"><div class="dh"><b>' . PT_DIAS_SEM[$d['dow']] . ' ' . ptBrData($d['data']) . '</b>' .
                  ($d['trabalhado_min'] !== null ? '<span>Trabalhado ' . ptHoras($d['trabalhado_min']) . '</span>' : '') . '</div>';
        if ($d['alerta']) $corpo .= '<div class="av">⚠ ' . $h($d['alerta']) . '</div>';
        $corpo .= '<div class="bs">';
        foreach ($d['batidas'] as $b) {
            $foto = '';
            if ($b['tem_foto'] && $fotos) {
                if ($nFotos < $limiteFotos) { $stF->execute(['id' => $b['id']]); $f = $stF->fetchColumn(); if ($f) { $foto = '<img src="' . $h($f) . '" alt="Foto da batida">'; $nFotos++; } }
                else $omitidas++;
            }
            $sit = $b['status'] . ($b['remocao_status'] === 'pendente' ? ' · remoção pendente' : '');
            $gps = $b['lat'] !== null ? '<div class="m">📍 ' . round($b['lat'], 5) . ', ' . round($b['lng'], 5) . ' (±' . (int)round($b['precisao'] ?? 0) . ' m)</div>' : '';
            $corpo .= '<div class="b' . ($b['status'] === 'recusada' || $b['status'] === 'removida' ? ' off' : '') . '">' . $foto . '<div class="t">' . $h(PT_LABEL[$b['tipo']] ?? $b['tipo']) . '</div><div class="hr">' . substr($b['momento'], 11, 5) .
                '</div><div class="m">' . $h($b['origem'] . ' · ' . $sit) . '</div>' . ($b['distancia'] !== null ? '<div class="m">similaridade ' . number_format((float)$b['distancia'], 2) . '</div>' : '') . $gps .
                ($b['justificativa'] ? '<div class="m j">“' . $h($b['justificativa']) . '”</div>' : '') . '</div>';
        }
        $corpo .= '</div></div>';
    }
    if ($atual !== null) $corpo .= '</section>';
    if (!$dias) $corpo = '<p>Sem registros no período.</p>';
    $aviso = $omitidas ? '<p class="av">Por limite de tamanho, ' . $omitidas . ' foto(s) não foram incluídas neste relatório. Reduza o período ou filtre por colaborador.</p>' : '';
    return '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Registros de ponto ' . ptBrData($ini) . ' a ' . ptBrData($fim) . '</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>' .
        'body{font:13px/1.4 Arial,Helvetica,sans-serif;color:#111;margin:0;padding:18px;background:#fff}h1{font-size:18px;margin:0 0 2px}h2{font-size:15px;margin:18px 0 6px;text-transform:uppercase;border-bottom:2px solid #1d4ed8;padding-bottom:3px}' .
        '.sub{color:#555;margin-bottom:10px}.dia{border:1px solid #d5d9e0;border-radius:6px;padding:6px 8px;margin:6px 0;page-break-inside:avoid}.dia.al{border-color:#e11d48;background:#fff5f6}' .
        '.dh{display:flex;justify-content:space-between;gap:8px}.dh span{color:#555}.av{color:#b91c1c;font-weight:bold;margin:3px 0}.bs{display:flex;flex-wrap:wrap;gap:8px;margin-top:4px}' .
        '.b{width:150px;border:1px solid #e2e5ea;border-radius:6px;padding:5px;background:#fafbfc}.b.off{opacity:.55}.b img{width:100%;height:110px;object-fit:cover;border-radius:4px;display:block;margin-bottom:4px}' .
        '.t{font-size:11px;color:#444;font-weight:bold}.hr{font-size:18px;font-weight:800}.m{font-size:10px;color:#666}.j{font-style:italic}' .
        '.bt{position:fixed;top:10px;right:10px;background:#1d4ed8;color:#fff;border:0;border-radius:6px;padding:8px 14px;font-weight:bold;cursor:pointer}' .
        '@media print{.bt{display:none}body{padding:0}h2{page-break-after:avoid}}</style></head><body><button class="bt" onclick="window.print()">Imprimir / Salvar PDF</button>' .
        '<h1>SmartNOC Ponto — Registros de batidas</h1><div class="sub">Período: ' . ptBrData($ini) . ' a ' . ptBrData($fim) . ($filtro !== '' ? ' · Colaborador: ' . $h($filtro) : ' · Todos os colaboradores') .
        ' · Gerado em ' . date('d/m/Y H:i') . ' por ' . $h($por) . '</div>' . $aviso . $corpo . ($auto ? '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},500);});</script>' : '') . '</body></html>';
}

/* ---------- e-mails da folha ---------- */
function ptEmailDoColab($pdo, $nome) {
    $st = $pdo->prepare("SELECT email FROM ponto_colaboradores WHERE nome = :n"); $st->execute(['n' => $nome]);
    $e = trim((string)$st->fetchColumn());
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
}
// Datas da folha que o colaborador precisa regularizar: dias já encerrados em que faltam batidas (e sem pedido de inclusão ou abono em andamento/aprovado)
function ptDatasRegularizar($pdo, $regra, $nome, $ref, $folha = null) {
    $f = $folha ?: ptFolha($pdo, $regra, $nome, $ref);
    $l = [];
    foreach ($f['dias'] as $d) {
        if (!$d['alerta'] || $d['hoje'] || $d['futuro']) continue;
        $abonado = false;
        foreach ($d['abonos'] as $a) if ($a['tipo'] === 'dia') $abonado = true; // atestado/abono de dia inteiro (aprovado ou aguardando o gestor)
        if ($abonado) continue;
        $hs = []; foreach (ptAtivas($d['batidas']) as $b) $hs[$b['tipo']] = substr($b['momento'], 11, 5); // batidas corretas do dia
        $l[] = ['data' => $d['data'], 'dow' => $d['dow'], 'motivo' => $d['alerta'], 'horas' => $hs, 'previsto_min' => (int)($d['previsto_min'] ?? 0), 'trab_min' => (int)($d['trab_parcial_min'] ?? 0)];
    }
    return $l;
}
function ptMesAno($ref) {
    $m = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    return $m[(int)substr($ref, 5, 2) - 1] . ' de ' . substr($ref, 0, 4);
}
function ptEmailLayout($titulo, $corpoHtml) {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<!doctype html><html><head><meta charset="utf-8"><meta name="color-scheme" content="light only"><meta name="supported-color-schemes" content="light only"><style>:root{color-scheme:light only}</style></head><body style="margin:0;background:#eef1f6;font-family:Arial,Helvetica,sans-serif;color:#1f2937">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;padding:24px 8px"><tr><td align="center">' .
        '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:580px;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #dde2ea">' .
        '<tr><td style="background:#ffffff;color:#0b1220;padding:16px 22px;font-size:15px;font-weight:bold;border-bottom:3px solid #1d4ed8">⏱ SmartNOC · Ponto</td></tr>' .
        '<tr><td style="padding:22px"><h1 style="margin:0 0 12px;font-size:19px;color:#0b1220">' . $h($titulo) . '</h1>' . $corpoHtml . '</td></tr>' .
        '<tr><td style="padding:12px 22px;background:#f6f8fb;color:#6b7280;font-size:11px">Mensagem automática do sistema de ponto. Em caso de dúvida, fale com o seu supervisor.</td></tr>' .
        '</table></td></tr></table></body></html>';
}
function ptEmailMontar($nomeCompleto, $nome, $f, $datas, $tipo, $por = '') {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $prim = ucfirst(mb_strtolower(explode(' ', trim($nomeCompleto ?: $nome))[0]));
    $mes = ptMesAno($f['ref']); $per = ptBrData($f['inicio']) . ' a ' . ptBrData($f['fim']);
    $url = ptUrl() . '/folha.html';
    $btn = '<p style="margin:18px 0"><a href="' . $h($url) . '" style="background:#1d4ed8;color:#ffffff;text-decoration:none;padding:11px 20px;border-radius:7px;font-weight:bold;display:inline-block">Abrir minha folha de ponto</a></p>';
    $lista = ''; $txtLista = '';
    $CURTO = ['ENTRADA' => 'Entrada', 'SAIDA_ALMOCO' => 'Almoço', 'RETORNO_ALMOCO' => 'Almoço', 'SAIDA' => 'Saída'];
    $DCURTO = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    foreach ($datas as $d) { // um cartão por data, no estilo da folha: batida correta em verde, a regularizar em vermelho
        $cel = ''; $faltam = [];
        foreach ($CURTO as $t => $rot) {
            $hr = $d['horas'][$t] ?? null;
            if ($hr === null) $faltam[] = PT_LABEL[$t];
            $est = $hr !== null ? 'border:1px solid #16a34a;background:#ecfdf5;color:#166534' : 'border:1px solid #dc2626;background:#fef2f2;color:#b91c1c';
            $cel .= '<td width="17%" align="center" style="' . $est . ';padding:6px 2px"><div style="font-size:9px;font-weight:bold;text-transform:uppercase;letter-spacing:.4px">' . $rot . '</div><div style="font-size:16px;font-weight:bold;line-height:1.35">' . ($hr ?? '—') . '</div></td>';
        }
        $chip = '<span style="background:' . (count($d['horas']) ? '#fef3c7;color:#92400e' : '#fee2e2;color:#b91c1c') . ';font-size:10px;font-weight:bold;text-transform:uppercase;padding:2px 8px;border-radius:10px">' . (count($d['horas']) ? 'Incompleto' : 'Pendente') . '</span>'; // INCOMPLETO em amarelo, PENDENTE em vermelho
        $tot = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:12px"><tr><td style="color:#6b7280;font-size:10px;font-weight:bold;text-transform:uppercase;padding:2px 0">Previstas</td><td align="right" style="font-weight:bold;color:#111827">' . ptHoras($d['previsto_min']) . '</td></tr>' .
               '<tr><td style="color:#6b7280;font-size:10px;font-weight:bold;text-transform:uppercase;padding:2px 0">Trabalhadas</td><td align="right" style="font-weight:bold;color:#111827">' . ptHoras($d['trab_min']) . '</td></tr></table>';
        $lista .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #dde2ea;border-radius:10px;margin:0 0 10px;background:#ffffff"><tr><td style="padding:10px 12px">' .
            '<div style="font-size:15px;font-weight:bold;color:#111827;margin-bottom:8px">' . $DCURTO[$d['dow']] . ' ' . substr(ptBrData($d['data']), 0, 5) . '/' . substr($d['data'], 0, 4) . ' &nbsp;' . $chip . '</div>' .
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="4"><tr>' . $cel . '<td width="28%" style="padding-left:8px">' . $tot . '</td></tr></table></td></tr></table>';
        $txtLista .= '  - ' . ptBrData($d['data']) . ' (' . PT_DIAS_SEM[$d['dow']] . ') — faltam: ' . implode(', ', $faltam) . "\n";
    }
    $legenda = '<p style="font-size:11px;color:#6b7280;margin:6px 0"><b style="color:#16a34a">■ Verde</b>: batida registrada &nbsp;·&nbsp; <b style="color:#dc2626">■ Vermelho</b>: precisa regularizar</p>';
    $tabela = $datas ? '<div style="margin:10px 0">' . $legenda . $lista . '</div>' : '';
    $como = '<p style="font-size:13px;color:#4b5563">Para regularizar: clique em <b>Abrir minha folha de ponto</b>, localize o dia indicado e clique no campo da batida (entrada, almoço ou saída) para solicitar a <b>inclusão</b> ou a <b>remoção</b> do horário, informando uma justificativa.</p>';
    if ($tipo === 'manual') {
        $assunto = 'Regularize seu ponto — folha de ' . $mes;
        $html = '<p>Olá, <b>' . $h($prim) . '</b>.</p><p>' . ($por ? 'O supervisor <b>' . $h($por) . '</b> pediu' : 'Pedimos') . ' que você regularize as batidas das datas abaixo (folha de ' . $h($mes) . ', ' . $h($per) . '):</p>' . $tabela . $como . $btn;
        $txt = "Olá, $prim.\n\n" . ($por ? "O supervisor $por pediu" : 'Pedimos') . " que você regularize as batidas das datas abaixo (folha de $mes, $per):\n$txtLista\nAbra a folha: $url\n";
        return [$assunto, ptEmailLayout('Há datas para regularizar', $html), $txt];
    }
    $r = $f['resumo'];
    $res = '<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #eceff4;border-radius:6px;margin:12px 0;font-size:13px">' .
        implode('', array_map(fn($k) => '<tr><td style="padding:6px 10px;border-bottom:1px solid #eceff4;color:#4b5563">' . $k[0] . '</td><td style="padding:6px 10px;border-bottom:1px solid #eceff4;text-align:right;font-weight:bold">' . $k[1] . '</td></tr>',
            [['Horas previstas', ptHoras($r['previsto_min'])], ['Horas trabalhadas', ptHoras($r['trabalhado_min'])], ['Hora extra', ptHoras($r['extra_min'])], ['Horas a pagar (descontar)', ptHoras($r['pagar_min'])]])) . '</table>';
    $anexo = '<p style="font-size:12px;color:#6b7280">O espelho de ponto desta folha segue em anexo (PDF).</p>';
    if (!$datas) {
        $assunto = 'Parabéns! Seu ponto de ' . $mes . ' está em dia';
        $html = '<p>Olá, <b>' . $h($prim) . '</b>! 🎉</p><p>A folha de ponto de <b>' . $h($mes) . '</b> (' . $h($per) . ') foi encerrada e <b>não encontramos nenhuma data a regularizar</b>. Parabéns pela dedicação e pelos registros em dia!</p>' . $res .
            '<p style="font-size:13px;color:#4b5563">Confira o resumo e o espelho em anexo. Se notar qualquer divergência, solicite o ajuste na sua folha.</p>' . $anexo . $btn;
        $txt = "Olá, $prim!\n\nA folha de ponto de $mes ($per) foi encerrada e não encontramos nenhuma data a regularizar. Parabéns pelos registros em dia!\n\nPrevistas " . ptHoras($r['previsto_min']) . ' · Trabalhadas ' . ptHoras($r['trabalhado_min']) . ' · Hora extra ' . ptHoras($r['extra_min']) . ' · A pagar ' . ptHoras($r['pagar_min']) . "\n\n$url\n";
        return [$assunto, ptEmailLayout('Parabéns, seu ponto está em dia!', $html), $txt];
    }
    $assunto = 'Ação necessária: regularize seu ponto de ' . $mes;
    $html = '<p>Olá, <b>' . $h($prim) . '</b>.</p><p>A folha de ponto de <b>' . $h($mes) . '</b> (' . $h($per) . ') foi encerrada e <b>' . (count($datas) === 1 ? 'há 1 data' : 'há ' . count($datas) . ' datas') . ' a regularizar</b>:</p>' .
        $tabela . $como . $res . $anexo . $btn;
    $txt = "Olá, $prim.\n\nA folha de ponto de $mes ($per) foi encerrada. Há " . count($datas) . " data(s) a regularizar:\n$txtLista\nSolicite a inclusão ou remoção da batida em: $url\n\nPrevistas " . ptHoras($r['previsto_min']) . ' · Trabalhadas ' . ptHoras($r['trabalhado_min']) . "\n";
    return [$assunto, ptEmailLayout('Regularize seu ponto', $html), $txt];
}
function ptEmailRegistrar($pdo, $nome, $ref, $tipo, $email, $ok, $erro, $qtd, $por) {
    try {
        $pdo->prepare("INSERT INTO ponto_email_envios (nome, ref, tipo, email, ok, erro, qtd_datas, enviado_por) VALUES (:n, :r, :t, :e, :ok, :er, :q, :p)")
            ->execute(['n' => $nome, 'r' => $ref, 't' => $tipo, 'e' => $email, 'ok' => $ok ? 'true' : 'false', 'er' => $erro ? substr($erro, 0, 500) : null, 'q' => $qtd, 'p' => $por]);
    } catch (Exception $e) {}
}
// Envia o e-mail da folha $ref ao colaborador. $tipo: 'manual' (só se houver datas) ou 'fim_folha' (relatório; parabéns se não houver datas).
// Devolve ['ok' => bool, 'erro' => string, 'datas' => int, 'enviado' => bool].
function ptEnviarEmailFolha($pdo, $nome, $ref, $tipo, $por = '') {
    $email = ptEmailDoColab($pdo, $nome);
    if (!$email) return ['ok' => false, 'erro' => 'Colaborador sem e-mail cadastrado (aba Colaboradores).', 'datas' => 0, 'enviado' => false];
    $cfg = smtpConfigLer($pdo);
    if (!smtpConfigurado($cfg)) return ['ok' => false, 'erro' => 'SMTP não configurado. Preencha em Escala → Configurações → E-mail (SMTP).', 'datas' => 0, 'enviado' => false];
    $regra = ptRegra($pdo, $nome);
    $f = ptFolha($pdo, $regra, $nome, $ref);
    $datas = ptDatasRegularizar($pdo, $regra, $nome, $ref, $f);
    if ($tipo === 'manual' && !$datas) return ['ok' => true, 'erro' => '', 'datas' => 0, 'enviado' => false];
    $st = $pdo->prepare("SELECT nome_completo FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
    [$assunto, $html, $txt] = ptEmailMontar((string)$st->fetchColumn(), $nome, $f, $datas, $tipo, $por);
    $anexos = [];
    if ($tipo === 'fim_folha') {
        try { require_once __DIR__ . '/ponto_pdf.php'; $bin = ptEspelhoPdf($pdo, $regra, $nome, $ref);
              if ($bin) $anexos[] = ['nome' => 'folha-ponto-' . substr($ref, 5, 2) . '-' . substr($ref, 0, 4) . '.pdf', 'tipo' => 'application/pdf', 'dados' => $bin]; }
        catch (Throwable $e) { error_log('ponto: espelho em PDF para o e-mail: ' . $e->getMessage()); }
    }
    $r = smtpEnviar($cfg, $email, $assunto, $html, $txt, $anexos);
    ptEmailRegistrar($pdo, $nome, $ref, $tipo, $email, $r['ok'], $r['erro'], count($datas), $por ?: 'sistema');
    return ['ok' => $r['ok'], 'erro' => $r['erro'], 'datas' => count($datas), 'enviado' => $r['ok']];
}
// Folha que deve receber o relatório agora (a que acabou de fechar), ou null se não é a hora / a opção está desligada / não há SMTP
function ptEmailRefPendente($pdo, $agora = null) {
    $agora = $agora ?: time();
    if (!ptEmailAuto($pdo)) return null;
    $hoje = date('Y-m-d', $agora);
    $ref = ptFolhaDeslocar(ptFolhaRefDe($hoje), -1);
    [$ini, $fim] = ptFolhaPeriodo($ref);
    if ($hoje <= $fim || $hoje > date('Y-m-d', strtotime("$fim +" . PT_EMAIL_JANELA_DIAS . ' days'))) return null;
    if ($hoje === date('Y-m-d', strtotime("$fim +1 day")) && (int)date('G', $agora) < PT_EMAIL_HORA_MIN) return null;
    if (!smtpConfigurado(smtpConfigLer($pdo))) return null;
    return $ref;
}
// Relatório automático de fim de folha. $limite: máximo de e-mails nesta rodada (0 = todos). Devolve o número de e-mails enviados.
function ptEmailFimFolhaSeNecessario($pdo, $limite = 0, $agora = null) {
    $ref = ptEmailRefPendente($pdo, $agora);
    if (!$ref) return 0;
    if (!$pdo->query("SELECT pg_try_advisory_lock(778801)")->fetchColumn()) return 0; // outra rodada em andamento
    $enviados = 0;
    try {
        $q = $pdo->prepare("SELECT f.nome FROM funcionarios f LEFT JOIN ponto_colaboradores p ON p.nome = f.nome
            WHERE COALESCE(p.ativo, TRUE) AND COALESCE(f.ativo, TRUE) AND p.email IS NOT NULL AND p.email <> '' AND (f.data_admissao IS NULL OR f.data_admissao <= :fim)
              AND NOT EXISTS (SELECT 1 FROM ponto_email_envios e WHERE e.nome = f.nome AND e.ref = :r AND e.tipo = 'fim_folha' AND e.ok)
              AND (SELECT count(*) FROM ponto_email_envios e WHERE e.nome = f.nome AND e.ref = :r2 AND e.tipo = 'fim_folha' AND NOT e.ok) < " . PT_EMAIL_TENTATIVAS . "
              AND NOT EXISTS (SELECT 1 FROM ponto_email_envios e WHERE e.nome = f.nome AND e.ref = :r3 AND e.tipo = 'fim_folha' AND NOT e.ok AND e.criado_em > now() - interval '30 minutes')
            ORDER BY f.nome");
        $q->execute(['r' => $ref, 'r2' => $ref, 'r3' => $ref, 'fim' => ptFolhaPeriodo($ref)[1]]);
        foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $nome) {
            if ($limite && $enviados >= $limite) break;
            $r = ptEnviarEmailFolha($pdo, $nome, $ref, 'fim_folha', 'sistema');
            if ($r['enviado']) $enviados++;
            elseif ($r['erro'] !== '' && stripos($r['erro'], 'SMTP não configurado') !== false) break;
        }
    } finally { $pdo->query("SELECT pg_advisory_unlock(778801)"); }
    return $enviados;
}
// Gatilho pelo próprio site (para quem não configurar o cron): roda no fim da requisição, no máximo a cada 5 minutos
function ptEmailFimDaRequisicao($pdo) {
    try {
        $st = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'ponto_email_tick'"); $st->execute(); $v = $st->fetchColumn();
        if ($v !== false && time() - (int)$v < 300) return;
        $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('ponto_email_tick', :v) ON CONFLICT (chave) DO UPDATE SET valor = EXCLUDED.valor")->execute(['v' => (string)time()]);
        if (!ptEmailRefPendente($pdo)) return;
        $php = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
        if (function_exists('exec') && stripos((string)ini_get('disable_functions'), 'exec') === false) { // processo em segundo plano: não atrasa a requisição de ninguém
            exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg(__DIR__ . '/ponto_cron_email.php') . ' > /dev/null 2>&1 &');
        } else ptEmailFimFolhaSeNecessario($pdo, PT_EMAIL_LOTE_WEB);
    } catch (Throwable $e) { error_log('ponto: e-mail de fim de folha: ' . $e->getMessage()); }
}
