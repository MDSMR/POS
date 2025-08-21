<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';
pos_session_start();

$username = trim($_POST['username'] ?? '');
$pin = trim($_POST['pin'] ?? '');

if ($username === '' || $pin === '') {
  header('Location: '.base_url('views/pos/login.php?e=Missing+credentials')); exit;
}

// POS roles allowed
$allowed = ['pos_manager','pos_headwaiter','pos_waiter','pos_cashier'];

$st = db()->prepare("SELECT id,tenant_id,username,name,role_key,pass_code FROM users WHERE username=:u LIMIT 1");
$st->execute([':u'=>$username]);
$u = $st->fetch();

if (!$u || !in_array($u['role_key'], $allowed, true) || empty($u['pass_code']) || !password_verify($pin, $u['pass_code'])) {
  header('Location: '.base_url('views/pos/login.php?e=Invalid+username%2FPIN+or+role')); exit;
}

// Store minimal POS session
$_SESSION['pos']['user'] = [
  'id'=>(int)$u['id'],
  'tenant_id'=>(int)$u['tenant_id'],
  'username'=>$u['username'],
  'name'=>$u['name'],
  'role_key'=>$u['role_key'],
];

header('Location: '.base_url('views/pos/index.php'));