<?php
// pos/do_login.php — verifies username + PIN, sets POS session
require_once __DIR__ . '/../config/db.php';

use_pos_session();

function fail(string $msg): void {
    $_SESSION['pos_flash'] = $msg;
    header('Location: login.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$pin      = (string)($_POST['pin'] ?? '');

if ($username === '' || $pin === '') {
    fail('Please enter both username and PIN.');
}

$tenantId = tenant_id();

$stmt = db()->prepare("
    SELECT id, tenant_id, name, username, email, pass_code, role_key, disabled_at
    FROM users
    WHERE tenant_id = :t AND username = :u
    LIMIT 1
");
$stmt->execute([':t' => $tenantId, ':u' => $username]);
$user = $stmt->fetch();

if (!$user)            fail('User not found or not in this tenant.');
if (!empty($user['disabled_at'])) fail('This user is disabled.');
if (empty($user['pass_code']))    fail('No PIN set for this user.');

if (!password_verify($pin, $user['pass_code'])) {
    fail('Invalid PIN.');
}

// Load permissions for this role
$permStmt = db()->prepare("
    SELECT permission_key, is_allowed
    FROM pos_role_permissions
    WHERE role_key = :rk
");
$permStmt->execute([':rk' => $user['role_key']]);
$perms = [];
foreach ($permStmt as $row) {
    $perms[$row['permission_key']] = (int)$row['is_allowed'];
}

// Store POS session
$_SESSION['pos_user'] = [
    'id'        => (int)$user['id'],
    'tenant_id' => (int)$user['tenant_id'],
    'name'      => $user['name'],
    'username'  => $user['username'],
    'role_key'  => $user['role_key'],
];
$_SESSION['pos_permissions'] = $perms;

header('Location: index.php');
exit;