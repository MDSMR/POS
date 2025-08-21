<?php
// controllers/items/branch_availability_reset.php — remove all overrides for an item
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId  = tenant_id();
$productId = (int)($_GET['product_id'] ?? 0);

if ($productId <= 0) {
  header('Location: ' . base_url('views/admin/items/index.php'));
  exit;
}

// Ensure product belongs to tenant
$st = db()->prepare("SELECT id FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
$st->execute([':id'=>$productId, ':t'=>$tenantId]);
if ($st->fetch()) {
  $del = db()->prepare("DELETE FROM product_branch_availability WHERE product_id=:pid");
  $del->execute([':pid'=>$productId]);
}

header('Location: ' . base_url('views/admin/items/branch_availability.php?product_id=' . $productId));