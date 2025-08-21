<?php
// controllers/items/pvg_detach.php — detach a variation group from a product
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId  = tenant_id();
$productId = (int)($_GET['product_id'] ?? 0);
$groupId   = (int)($_GET['group_id'] ?? 0);

if ($productId <= 0 || $groupId <= 0) {
  header('Location: '.base_url('views/admin/items/index.php'));
  exit;
}

// Ensure product is tenant's
$st = db()->prepare("SELECT id FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
$st->execute([':id'=>$productId, ':t'=>$tenantId]);
if (!$st->fetch()) {
  header('Location: '.base_url('views/admin/items/index.php')); exit;
}

try {
  $del = db()->prepare("DELETE FROM product_variation_groups WHERE product_id=:pid AND group_id=:gid");
  $del->execute([':pid'=>$productId, ':gid'=>$groupId]);
} catch (Throwable $e) {
  // ignore
}

header('Location: '.base_url('views/admin/items/variations.php?product_id='.$productId));