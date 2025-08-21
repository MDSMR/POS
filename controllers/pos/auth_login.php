<?php
// controllers/pos/auth_login.php — POS PIN login handler
declare(strict_types=1);

require_once __DIR__ . '/../../middleware/pos_auth.php'; // defines base_url + session
pos_session_start();

$debug = isset($_GET['debug']) && $_GET['debug']=='1';

// Load DB config (must be at public_html/config/db.php)
$configPath = __DIR__ . '/../../config/db.php';
if (!is_file($configPath)) {
  $msg = 'Config not found at /config/db.php';
  header('Location: '.base_url('pos/login.php?e='.rawurlencode($msg))); exit;
}
require_once $configPath;

// Validate db() helper or $pdo
if (!function_exists('db')) {
  if (isset($pdo) && $pdo instanceof PDO) {
    function db(): PDO { global $pdo; return $pdo; }
  } else {
    $msg = 'Database helper not available (db() or $pdo)';
    header('Location: '.base_url('pos/login.php?e='.rawurlencode($msg))); exit;
  }
}

$username = trim($_POST['username'] ?? '');
$pin      = trim($_POST['pin'] ?? '');

if ($username === '' || $pin === '') {
  header('Location: '.base_url('pos/login.php?e=Missing+credentials')); exit;
}

try {
  $allowedRoles = ['pos_manager','pos_headwaiter','pos_waiter','pos_cashier'];

  $st = db()->prepare("SELECT id,tenant_id,username,name,role_key,pass_code FROM users WHERE username=:u LIMIT 1");
  $st->execute([':u'=>$username]);
  $u = $st->fetch(PDO::FETCH_ASSOC);

  if (!$u || !in_array($u['role_key'], $allowedRoles, true) || empty($u['pass_code']) || !password_verify($pin, $u['pass_code'])) {
    header('Location: '.base_url('pos/login.php?e=Invalid+username%2FPIN+or+role')); exit;
  }

  $_SESSION['pos']['user'] = [
    'id'        => (int)$u['id'],
    'tenant_id' => (int)$u['tenant_id'],
    'username'  => (string)$u['username'],
    'name'      => (string)$u['name'],
    'role_key'  => (string)$u['role_key'],
  ];

  header('Location: '.base_url('pos/index.php'));
} catch (Throwable $e) {
  $msg = $debug ? ('Login error: '.$e->getMessage()) : 'Login error';
  header('Location: '.base_url('pos/login.php?e='.rawurlencode($msg))); exit;
}