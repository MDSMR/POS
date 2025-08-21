<?php
// scripts/diag/pdo_probe.php
// Quick MySQL probe to diagnose creds vs privileges vs db name.
// DELETE THIS FILE AFTER TESTING.

header('Content-Type: text/plain; charset=utf-8');

require __DIR__ . '/../../config/config.php';
$cfg = $config ?? require __DIR__ . '/../../config.php'; // support both includes
if (!isset($cfg['db'])) $cfg = require __DIR__ . '/../../config.php';

$host = $cfg['db']['host'];
$name = $cfg['db']['name'];
$user = $cfg['db']['user'];
$pass = $cfg['db']['pass'];
$charset = $cfg['db']['charset'] ?? 'utf8mb4';

echo "Host: $host\nDB: $name\nUser: $user\n\n";

// Try 1: connect with DB selected
try {
    $pdo = new PDO("mysql:host=$host;dbname=$name;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    echo "CONNECT WITH DB: OK\n";
} catch (Throwable $e) {
    echo "CONNECT WITH DB: FAIL\n";
    echo "  Code: " . ($e->getCode() ?? 'n/a') . "\n";
    echo "  Message: " . $e->getMessage() . "\n\n";
    // Keep going to next test
}

// Try 2: connect to host only (no db) -> shows if creds are valid
try {
    $pdo2 = new PDO("mysql:host=$host;charset=$charset", $user, $pass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]);
    echo "CONNECT WITHOUT DB: OK\n";
    // Try USE db
    try {
        $pdo2->exec("USE `$name`");
        echo "USE `$name`: OK\n";
        $tables = $pdo2->query("SHOW TABLES")->fetchAll(PDO::FETCH_NUM);
        echo "TABLE COUNT: " . count($tables) . "\n";
    } catch (Throwable $e) {
        echo "USE `$name`: FAIL\n";
        echo "  Code: " . ($e->getCode() ?? 'n/a') . "\n";
        echo "  Message: " . $e->getMessage() . "\n";
    }
} catch (Throwable $e) {
    echo "CONNECT WITHOUT DB: FAIL\n";
    echo "  Code: " . ($e->getCode() ?? 'n/a') . "\n";
    echo "  Message: " . $e->getMessage() . "\n";
}

echo "\nHint:\n- If CONNECT WITHOUT DB = FAIL → wrong user/pass or host\n- If CONNECT WITHOUT DB = OK but USE db = FAIL → user lacks privileges on that DB or DB name is wrong\n- If both OK but TABLE COUNT = 0 → you imported into a different DB name\n";