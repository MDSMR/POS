<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
  try {
    db()->beginTransaction();
    $d1 = db()->prepare("DELETE FROM product_categories WHERE product_id=:id");
    $d1->execute([':id'=>$id]);
    $d2 = db()->prepare("DELETE FROM products WHERE id=:id AND tenant_id=:t");
    $d2->execute([':id'=>$id, ':t'=>tenant_id()]);
    db()->commit();
  } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); }
}
header('Location: '.base_url('views/admin/items/index.php'));