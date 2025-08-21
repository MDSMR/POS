<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

echo "Smorll POS Health Check\n";
echo "-----------------------\n";

echo "PHP version: " . PHP_VERSION . "\n";
echo "SAPI: " . php_sapi_name() . "\n";

echo "\nSession:\n";
echo "  status: " . session_status() . " (0=DISABLED,1=NONE,2=ACTIVE)\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
    echo "  started new session. name=" . session_name() . "\n";
} else {
    echo "  active session. name=" . session_name() . "\n";
}

require_once __DIR__ . '/../config/db.php';

echo "\nDB test (read-only):\n";
try {
    $pdo = db();
    $row = $pdo->query("SELECT 1 AS ok")->fetch();
    echo "  connected OK. SELECT 1 => " . ($row['ok'] ?? '?') . "\n";
} catch (Throwable $e) {
    echo "  DB ERROR: " . $e->getMessage() . "\n";
}

echo "\nIf DB ERROR is 'Access denied', fix DB user privileges for database:\n";
echo "  DB_NAME=" . DB_NAME . "\n";
echo "  DB_USER=" . DB_USER . " @ localhost\n";
echo "\nDone.\n";