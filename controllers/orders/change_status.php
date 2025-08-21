<?php
// controllers/orders/change_status.php — update order status + audit log
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId = tenant_id();
$userId   = (int)(backend_user()['id'] ?? 0);
$id       = (int)($_GET['id'] ?? 0);
$status   = (string)($_GET['status'] ?? '');
$return   = (string)($_GET['return'] ?? base_url('views/admin/orders/index.php'));

$allowed = ['pending','preparing','ready','served','completed','cancelled'];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  header('Location: '.$return); exit;
}

// Ensure order belongs to tenant + get old status
$chk = db()->prepare("SELECT id, status FROM orders WHERE id=:id AND tenant_id=:t LIMIT 1");
$chk->execute([':id'=>$id, ':t'=>$tenantId]);
$old = $chk->fetch();
if (!$old) { header('Location: '.$return); exit; }

// Create audit table if needed (safe to run)
try {
  db()->exec("
    CREATE TABLE IF NOT EXISTS order_audit (
      id INT NOT NULL AUTO_INCREMENT,
      tenant_id INT NOT NULL,
      order_id INT NOT NULL,
      changed_by INT DEFAULT NULL,
      old_status VARCHAR(20) DEFAULT NULL,
      new_status VARCHAR(20) NOT NULL,
      note VARCHAR(255) DEFAULT NULL,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_oa_tenant (tenant_id),
      KEY idx_oa_order (order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
  ");
} catch (Throwable $e) {}

try {
  db()->beginTransaction();

  // Update order
  $u = db()->prepare("UPDATE orders SET status=:s, updated_at=NOW() WHERE id=:id");
  $u->execute([':s'=>$status, ':id'=>$id]);

  // Insert audit row
  $a = db()->prepare("INSERT INTO order_audit (tenant_id, order_id, changed_by, old_status, new_status, note)
                      VALUES (:t,:oid,:uid,:old,:new,:note)");
  $a->execute([
    ':t'=>$tenantId, ':oid'=>$id, ':uid'=>$userId,
    ':old'=>$old['status'], ':new'=>$status, ':note'=>null
  ]);

  db()->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
}
header('Location: '.$return);