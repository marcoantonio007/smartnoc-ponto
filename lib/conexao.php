<?php
// conexao.php — conexão com o banco da escala (mesmo Postgres), configurada por variáveis de ambiente.
$pdo = null;
try {
    $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', getenv('DB_HOST') ?: 'escala-postgres', getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'escala_db');
    $pdo = new PDO($dsn, getenv('DB_USER') ?: 'escala_user', getenv('DB_PASS') ?: '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
} catch (Exception $e) {
    error_log('ponto: falha na conexão com o banco: ' . $e->getMessage());
    $pdo = null;
}
