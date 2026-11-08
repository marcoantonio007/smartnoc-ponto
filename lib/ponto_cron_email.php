<?php
// ponto_cron_email.php — envia o relatório de fim de folha por e-mail (rodar pelo cron do servidor; veja as instruções de instalação).
// Opcional: um horário de teste como argumento (ex.: php ponto_cron_email.php "2026-11-15 09:00") simula a execução naquele momento.
// Ex.: */30 * * * * docker exec site-ponto php /var/www/lib/ponto_cron_email.php >> /var/log/ponto_email.log 2>&1
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/ponto_lib.php';
require_once __DIR__ . '/ponto_email.php';
if (!$pdo) { fwrite(STDERR, "sem conexão com o banco\n"); exit(1); }
try {
    ptGarantirTabelas($pdo); ptEmailGarantir($pdo);
    $n = ptEmailFimFolhaSeNecessario($pdo, 0, isset($argv[1]) ? (strtotime($argv[1]) ?: null) : null);
    echo date('Y-m-d H:i:s') . " relatórios de fim de folha enviados: $n\n";
} catch (Throwable $e) { fwrite(STDERR, date('Y-m-d H:i:s') . ' erro: ' . $e->getMessage() . "\n"); exit(1); }
