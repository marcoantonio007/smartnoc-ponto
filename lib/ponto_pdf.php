<?php
// ponto_pdf.php — Espelho de ponto em PDF (sempre 1 folha A4). Gerador de PDF próprio (sem bibliotecas): fontes padrão Helvetica.
if (!defined('PT_EMPRESA_NOME')) define('PT_EMPRESA_NOME', 'AVANTIA TECNOLOGIA E ENGENHARIA S/A');
if (!defined('PT_EMPRESA_CNPJ')) define('PT_EMPRESA_CNPJ', '02.543.302/0001-31');

class PtPdf {
    private $c = ''; public $W = 595.28; public $H = 841.89; private $img = null;
    private static $w = [32=>278,33=>278,34=>355,35=>556,36=>556,37=>889,38=>667,39=>191,40=>333,41=>333,42=>389,43=>584,44=>278,45=>333,46=>278,47=>278,
        48=>556,49=>556,50=>556,51=>556,52=>556,53=>556,54=>556,55=>556,56=>556,57=>556,58=>278,59=>278,60=>584,61=>584,62=>584,63=>556,64=>1015,
        65=>667,66=>667,67=>722,68=>722,69=>667,70=>611,71=>778,72=>722,73=>278,74=>500,75=>667,76=>556,77=>833,78=>722,79=>778,80=>667,81=>778,82=>722,
        83=>667,84=>611,85=>722,86=>667,87=>944,88=>667,89=>667,90=>611,91=>278,92=>278,93=>278,94=>469,95=>556,96=>333,97=>556,98=>556,99=>500,100=>556,
        101=>556,102=>278,103=>556,104=>556,105=>222,106=>222,107=>500,108=>222,109=>833,110=>556,111=>556,112=>556,113=>556,114=>333,115=>500,116=>278,
        117=>556,118=>500,119=>722,120=>500,121=>500,122=>500,123=>334,124=>260,125=>334,126=>584];
    static function cp($s) { $r = @iconv('UTF-8', 'CP1252//TRANSLIT//IGNORE', (string)$s); return $r === false ? '' : $r; }
    static function larg($s, $size, $bold = false) {
        $t = 0; foreach (str_split(self::cp($s)) as $ch) { $o = ord($ch); $t += self::$w[$o] ?? ($o > 191 ? (self::$w[ord(strtolower(iconv('CP1252', 'ASCII//TRANSLIT', $ch) ?: 'a')[0] ?? 'a')] ?? 556) : 556); }
        return $t / 1000 * $size * ($bold ? 1.07 : 1);
    }
    // texto com a linha de base em $y (origem no topo da página); align: l, r, c
    function texto($x, $y, $s, $size, $bold = false, $align = 'l', $cinza = 0.0) {
        $w = self::larg($s, $size, $bold);
        if ($align === 'r') $x -= $w; elseif ($align === 'c') $x -= $w / 2;
        $e = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], self::cp($s));
        $this->c .= sprintf("BT /%s %.2f Tf %.2f g %.2f %.2f Td (%s) Tj ET\n", $bold ? 'F2' : 'F1', $size, $cinza, $x, $this->H - $y, $e);
    }
    function ajustado($x, $y, $s, $size, $larguraMax, $bold = false, $align = 'l', $min = 4.6) { // reduz a fonte até caber na largura
        while ($size > $min && self::larg($s, $size, $bold) > $larguraMax) $size -= 0.2;
        $this->texto($x, $y, $s, $size, $bold, $align);
    }
    function linha($x1, $y1, $x2, $y2, $esp = 0.5, $cinza = 0.0) {
        $this->c .= sprintf("%.2f G %.2f w %.2f %.2f m %.2f %.2f l S\n", $cinza, $esp, $x1, $this->H - $y1, $x2, $this->H - $y2);
    }
    function retang($x, $y, $w, $h, $cinza) { $this->c .= sprintf("%.2f g %.2f %.2f %.2f %.2f re f\n", $cinza, $x, $this->H - $y - $h, $w, $h); }
    // Logo da empresa (JPEG direto; PNG convertido pelo GD, com fundo branco). Retorna [largura, altura] em pixels ou null
    function carregaLogo($bin) {
        $inf = @getimagesizefromstring($bin); if (!$inf) return null;
        if ($inf[2] === IMAGETYPE_JPEG && ($inf['channels'] ?? 3) !== 4) {
            $this->img = ['w' => $inf[0], 'h' => $inf[1], 'cs' => ($inf['channels'] ?? 3) == 1 ? '/DeviceGray' : '/DeviceRGB', 'f' => '/DCTDecode', 'd' => $bin];
            return [$inf[0], $inf[1]];
        }
        if (!function_exists('imagecreatefromstring')) return null;
        $g = @imagecreatefromstring($bin); if (!$g) return null;
        $w = imagesx($g); $h = imagesy($g);
        if ($w > 500) { $g2 = imagescale($g, 500); if ($g2) { imagedestroy($g); $g = $g2; $w = imagesx($g); $h = imagesy($g); } }
        $fundo = imagecreatetruecolor($w, $h); imagefill($fundo, 0, 0, imagecolorallocate($fundo, 255, 255, 255)); imagealphablending($fundo, true); imagecopy($fundo, $g, 0, 0, 0, 0, $w, $h);
        $raw = ''; for ($y = 0; $y < $h; $y++) for ($x = 0; $x < $w; $x++) { $c = imagecolorat($fundo, $x, $y); $raw .= chr(($c >> 16) & 255) . chr(($c >> 8) & 255) . chr($c & 255); }
        $this->img = ['w' => $w, 'h' => $h, 'cs' => '/DeviceRGB', 'f' => '/FlateDecode', 'd' => gzcompress($raw, 6)];
        return [$w, $h];
    }
    function logo($x, $y, $w, $h) { if ($this->img) $this->c .= sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /Im1 Do Q\n", $w, $h, $x, $this->H - $y - $h); }
    function saida() {
        $cont = function_exists('gzcompress') ? gzcompress($this->c, 6) : $this->c; $filtro = function_exists('gzcompress') ? ' /Filter /FlateDecode' : '';
        $o = [];
        $o[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $o[2] = '<< /Type /Pages /Kids [3 0 R] /Count 1 >>';
        $o[3] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2f %.2f] /Contents 4 0 R /Resources << /Font << /F1 5 0 R /F2 6 0 R >>' . ($this->img ? ' /XObject << /Im1 8 0 R >>' : '') . ' >> >>', $this->W, $this->H);
        $o[4] = "<< /Length " . strlen($cont) . "$filtro >>\nstream\n" . $cont . "\nendstream";
        $o[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $o[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $o[7] = '<< /Title (Espelho de ponto) /Producer (SmartNOC Ponto) >>';
        if ($this->img) { $i = $this->img; $o[8] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace %s /BitsPerComponent 8 /Filter %s /Length %d >>\nstream\n", $i['w'], $i['h'], $i['cs'], $i['f'], strlen($i['d'])) . $i['d'] . "\nendstream"; }
        $pdf = "%PDF-1.4\n"; $off = [];
        foreach ($o as $n => $corpo) { $off[$n] = strlen($pdf); $pdf .= "$n 0 obj\n$corpo\nendobj\n"; }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 " . (count($o) + 1) . "\n0000000000 65535 f \n";
        foreach ($off as $f) $pdf .= sprintf("%010d 00000 n \n", $f);
        return $pdf . "trailer\n<< /Size " . (count($o) + 1) . " /Root 1 0 R /Info 7 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }
}

function ptHM($min) { $s = $min < 0 ? '-' : ''; $min = abs((int)$min); return $s . sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }

// Espelho de ponto da folha $ref de $nome: um PDF A4 em uma única página, qualquer que seja o número de dias
function ptEspelhoPdf($pdo, $regra, $nome, $ref) {
    $f = ptFolha($pdo, $regra, $nome, $ref, null, null);
    $st = $pdo->prepare("SELECT nome_completo, setor, local_base, localidade FROM funcionarios WHERE nome = :n"); $st->execute(['n' => $nome]);
    $fu = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $abonos = [];
    try {
        $q = $pdo->prepare("SELECT tipo, motivo, to_char(data_ini,'YYYY-MM-DD') AS i, to_char(data_fim,'YYYY-MM-DD') AS f, to_char(hora_ini,'HH24:MI') AS hi, to_char(hora_fim,'HH24:MI') AS hf
                            FROM ponto_atestados WHERE nome = :n AND status = 'aprovada' AND data_ini <= :b AND data_fim >= :a");
        $q->execute(['n' => $nome, 'a' => $f['inicio'], 'b' => $f['fim']]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) for ($d = max($r['i'], $f['inicio']); $d <= min($r['f'], $f['fim']); $d = date('Y-m-d', strtotime("$d +1 day")))
            $abonos[$d][] = $r;
    } catch (Exception $e) {}
    $fer = [];
    try { $q = $pdo->prepare("SELECT to_char(data_feriado,'MM-DD') AS md, nome FROM feriados WHERE tipo = 'nacional'"); $q->execute(); foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $fer[$r['md']] = $r['nome']; } catch (Exception $e) {}

    $pdf = new PtPdf(); $W = $pdf->W; $H = $pdf->H; $mx = 26; $dir = $W - $mx;
    $DS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $br = function ($d) { return substr($d, 8, 2) . '/' . substr($d, 5, 2) . '/' . substr($d, 0, 4); };
    // cabeçalho
    // logo da empresa no canto superior direito: a mesma "Logo Relatório PDF" da Escala (configuracoes.logo_pdf_base64);
    // se não houver, usa o arquivo lib/logo_empresa.png|jpg|jpeg. Nome e CNPJ ficam alinhados à direita, logo abaixo da logo.
    $yNome = 36; $yCnpj = 48; $bin = null;
    try {
        $q = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = 'logo_pdf_base64'"); $q->execute(); $v = $q->fetchColumn();
        if (is_string($v) && preg_match('#^data:image/[a-z+.-]+;base64,(.+)$#s', trim($v), $m)) $bin = base64_decode($m[1], true) ?: null;
    } catch (Exception $e) {}
    if (!$bin) foreach (['png', 'jpg', 'jpeg'] as $ext) { $arq = __DIR__ . '/logo_empresa.' . $ext; if (is_file($arq)) { $bin = file_get_contents($arq); break; } }
    if ($bin && ($d = $pdf->carregaLogo($bin))) {
        $lh = 34; $lw = $lh * $d[0] / $d[1]; if ($lw > 150) { $lw = 150; $lh = $lw * $d[1] / $d[0]; }
        $pdf->logo($dir - $lw, 18, $lw, $lh); $yNome = 18 + $lh + 11; $yCnpj = $yNome + 12;
    }
    $pdf->texto($dir, $yNome, PT_EMPRESA_NOME, 9.5, true, 'r'); $pdf->texto($dir, $yCnpj, 'CNPJ: ' . PT_EMPRESA_CNPJ, 8, false, 'r');
    $pdf->texto($mx, 70, mb_strtoupper($fu['nome_completo'] ?? '' ?: $nome), 11, true);
    $info = [];
    if (!empty($fu['setor'])) $info[] = 'DEPARTAMENTO ' . $fu['setor'];
    $loc = trim(($fu['local_base'] ?? '') . ' ' . ($fu['localidade'] ?? '')); if ($loc !== '') $info[] = 'LOCALIZAÇÃO ' . $loc;
    if ($info) $pdf->texto($mx, 83, implode('     ', $info), 8);
    $pdf->texto($W / 2, 108, 'ESPELHO DE PONTO', 12, true, 'c');
    $p = explode('-', $f['ref']);
    $pdf->texto($W / 2, 121, 'Período ' . $br($f['inicio']) . ' a ' . $br($f['fim']) . ' - Competência : ' . $p[1] . '/' . $p[0] . ' - Data de emissão ' . date('d/m/Y'), 8.5, false, 'c');

    // colunas
    $cx = [$mx, $mx + 88, $mx + 214, $mx + 322, $dir]; $topo = 132; $thH = 15;
    $pdf->retang($mx, $topo, $dir - $mx, $thH, 0.90);
    foreach (['DATA', 'BATIDAS', 'AJUSTES', 'RESULTADO'] as $i => $t) $pdf->texto($cx[$i] + 4, $topo + 10.5, $t, 8, true);
    $pdf->linha($mx, $topo, $dir, $topo, 0.8); $pdf->linha($mx, $topo + $thH, $dir, $topo + $thH, 0.8);

    $rodape = 78; $disp = $H - 24 - $rodape - ($topo + $thH); $n = max(1, count($f['dias'])); $rh = min(24, $disp / $n);
    $fz = max(5.2, min(7.6, $rh * 0.36)); $y = $topo + $thH; $manual = false;
    foreach ($f['dias'] as $d) {
        $dt = $d['data']; $dow = (int)date('w', strtotime($dt));
        $bat = []; $aj = [];
        foreach ($d['batidas'] as $b) {
            $h = substr($b['momento'], 11, 5); $m = $b['origem'] !== 'facial';
            if (in_array($b['status'], ['valida', 'aprovada'], true)) { $bat[] = $h . ($m ? '*' : ''); if ($m) $manual = true; }
            elseif ($b['status'] === 'pendente') $aj[] = $h . ' - Pendente';
            elseif (in_array($b['status'], ['recusada', 'removida', 'cancelada'], true)) $aj[] = $h . ' - Desconsiderado';
        }
        $ab = $abonos[$dt] ?? [];
        foreach ($ab as $a) $aj[] = 'Abono: ' . ($a['motivo'] ?: ($a['tipo'] === 'dia' ? 'Atestado médico' : 'Atestado de consulta')) . ($a['tipo'] === 'consulta' ? ' (' . $a['hi'] . '-' . $a['hf'] . ')' : '');
        $l1 = []; $l2 = [];
        $prev = (int)$d['previsto_min']; $trab = $d['trabalhado_min'] !== null ? (int)$d['trabalhado_min'] : (int)($d['trab_parcial_min'] ?? 0);
        $tem = (bool)$bat;
        if ($tem || $trab) $l1[] = 'Horas Trabalhadas : ' . ptHM($trab);
        if ($prev) $l1[] = 'Horas Previstas : ' . ptHM($prev);
        if (!$prev && $d['sem_obrigacao']) { $nf = $fer[substr($dt, 5, 5)] ?? null; $l1[] = !empty($d['pre_admissao']) ? 'Sem expediente' : ($ab ? 'Dia abonado' : (!empty($d['feriado']) ? $d['feriado'] : ($nf ? 'Feriado: ' . $nf : (ptEhFeriado($pdo, $dt) ? 'Feriado' : 'Folga')))); }
        if (!empty($d['extra_min'])) $l2[] = (($d['he_status'] ?? 'aprovada') === 'aprovada' ? 'HORA EXTRA : ' : 'HORA EXTRA (AGUARDANDO APROVACAO) : ') . ptHM($d['extra_min']);
        if (!empty($d['pagar_min'])) $l2[] = 'ATRASO : -' . ptHM($d['pagar_min']);
        if ($prev && !$d['futuro'] && !$d['hoje'] && $d['trabalhado_min'] === null) $l2[] = $tem ? 'BATIDAS INCOMPLETAS' : 'SEM BATIDAS';
        $base = $y + ($rh - 0) / 2 + $fz * 0.35;
        $pdf->texto($cx[0] + 4, $base, $br($dt) . ' - ' . $DS[$dow], $fz);
        $pdf->ajustado($cx[1] + 4, $base, $bat ? implode('  ', $bat) : '--', $fz, $cx[2] - $cx[1] - 8);
        if ($aj) {
            $duas = count($aj) > 1 && $rh >= 2 * $fz + 2; $a1 = $duas ? $aj[0] : implode(' | ', $aj);
            if ($duas) { $pdf->ajustado($cx[2] + 4, $y + $rh / 2 - 1, $a1, $fz * 0.9, $cx[3] - $cx[2] - 8); $pdf->ajustado($cx[2] + 4, $y + $rh / 2 + $fz, implode(' | ', array_slice($aj, 1)), $fz * 0.9, $cx[3] - $cx[2] - 8); }
            else $pdf->ajustado($cx[2] + 4, $base, $a1, $fz * 0.9, $cx[3] - $cx[2] - 8);
        }
        $t1 = implode('   ', $l1); $t2 = implode('   ', $l2); $lw = $cx[4] - $cx[3] - 8;
        if ($t1 !== '' && $t2 !== '' && $rh >= 2 * $fz + 2) { $pdf->ajustado($cx[3] + 4, $y + $rh / 2 - 1, $t1, $fz, $lw); $pdf->ajustado($cx[3] + 4, $y + $rh / 2 + $fz, $t2, $fz, $lw, true); }
        else { $pdf->ajustado($cx[3] + 4, $base, trim($t1 . ($t1 !== '' && $t2 !== '' ? '   ' : '') . $t2), $fz, $lw); }
        $y += $rh; $pdf->linha($mx, $y, $dir, $y, 0.25, 0.7);
    }
    $pdf->linha($mx, $y, $dir, $y, 0.8);
    $pdf->linha($mx, $topo + $thH, $mx, $y, 0.25, 0.7); $pdf->linha($dir, $topo + $thH, $dir, $y, 0.25, 0.7);
    // rodapé
    $r = $f['resumo']; $y += 13;
    $pdf->texto($mx, $y, 'TOTAIS DA FOLHA   Previstas: ' . ptHM($r['previsto_min']) . '   Trabalhadas: ' . ptHM($r['trabalhado_min']) . '   Hora extra: ' . ptHM($r['extra_min']) . '   A pagar: ' . ptHM($r['pagar_min']) . '   Saldo: ' . ptHM($r['saldo_min']), 8, true);
    $y += 14;
    $pdf->ajustado($mx, $y, 'Estou ciente dos registros de ponto e atesto que os horários são fidedignos ao da minha jornada de trabalho nos moldes do §2º do art. 74 da CLT e Portaria nº 671.', 7.2, $dir - $mx);
    if ($manual) { $y += 10; $pdf->texto($mx, $y, '* Dados inseridos/ajustados manualmente no sistema.', 7); }
    $pdf->linha($mx, $H - 34, $mx + 230, $H - 34, 0.5); $pdf->texto($mx, $H - 25, 'Assinatura do colaborador', 7);
    $pdf->linha($dir - 110, $H - 34, $dir, $H - 34, 0.5); $pdf->texto($dir - 110, $H - 25, 'Data', 7);
    return $pdf->saida();
}
