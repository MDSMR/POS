<?php
// controllers/items/pvg_save_order.php — update sort orders for attached groups
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId  = tenant_id();
$productId = (int)($_POST['product_id'] ?? 0);
$sorts     = $_POST['sort_order'] ?? []; // [group_id => sort]

if ($productId <= 0 || !is_array($sorts)) {
  header('Location: '.base_url('views/admin/items/index.php'));
  exit;
}

// Ensure product belongs to tenant
$st = db()->prepare("SELECT id FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
$st->execute([':id'=>$productId, ':t'=>$tenantId]);
if (!$st->fetch()) {
  header('Location: '.base_url('views/admin/items/index.php')); exit;
}

try {
  db()->beginTransaction();
  $up = db()->prepare("UPDATE product_variation_groups SET sort_order=:so WHERE product_id=:pid AND group_id=:gid");
  foreach ($sorts as $gid => $so) {
    $gid = (int)$gid; $so = (int)$so;
    if ($gid <= 0) continue;
    $up->execute([':so'=>$so, ':pid'=>$productId, ':gid'=>$gid]);
  }
  db()->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
}

header('Location: '.base_url('views/admin/items/variations.php?product_id='.$productId));