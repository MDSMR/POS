<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
  try {
    db()->beginTransaction();
    $d1 = db()->prepare("DELETE FROM variation_values WHERE group_id=:g");
    $d1->execute([':g'=>$id]);
    $d2 = db()->prepare("DELETE FROM product_variation_groups WHERE group_id=:g");
    $d2->execute([':g'=>$id]);
    $d3 = db()->prepare("DELETE FROM variation_groups WHERE id=:g AND tenant_id=:t");
    $d3->execute([':g'=>$id, ':t'=>tenant_id()]);
    db()->commit();
  } catch (Throwable $e) { if (db()->inTransaction()) db()->rollBack(); }
}
header('Location: '.base_url('views/admin/variations/index.php'));