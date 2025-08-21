<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId = tenant_id();
$id = (int)($_GET['id'] ?? 0);
if ($id>0) {
  try {
    $st = db()->prepare("DELETE FROM customers WHERE id=:id AND tenant_id=:t");
    $st->execute([':id'=>$id, ':t'=>$tenantId]);
  } catch (Throwable $e) {}
}
header('Location: '.base_url('views/admin/customers/index.php'));