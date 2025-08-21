<?php
// public_html/config/db.php
declare(strict_types=1);

/**
 * Credentials you shared
 */
const DB_NAME    = 'dbvtrnbzad193e';
const DB_USER    = 'u6yopmhusamog';
const DB_PASS    = '11|:66_2jh_';
const DB_CHARSET = 'utf8mb4';

/**
 * Return a shared PDO (tries multiple DSNs so POS/Backend won’t break if host uses socket/TCP)
 */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $candidates = [
        'mysql:host=localhost;port=3306;dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        'mysql:host=127.0.0.1;port=3306;dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        'mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        'mysql:unix_socket=/tmp/mysql.sock;dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
    ];

    $lastErr = null;
    foreach ($candidates as $dsn) {
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $opts);
            $pdo->exec('SET NAMES ' . DB_CHARSET);
            return $pdo;
        } catch (Throwable $e) {
            $lastErr = $e;
        }
    }

    throw new RuntimeException('Database connection failed: ' . ($lastErr ? $lastErr->getMessage() : 'Unknown error'));
}

/**
 * Backend session helper:
 * - If NO session: start a dedicated 'smorll_session' with secure cookie params.
 * - If there IS a session already (POS or anything): DO NOTHING (don’t switch/close).
 *   This prevents breaking POS flows.
 */
function use_backend_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name('smorll_session');
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 4, // 4 hours
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
    // If a session is already active, leave it as-is to avoid collisions with POS.
}