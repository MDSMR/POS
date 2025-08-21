<?php
// controllers/items/pvg_attach.php — attach a variation group to a product
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId  = tenant_id();
$productId = (int)($_POST['product_id'] ?? 0);
$groupId   = (int)($_POST['group_id'] ?? 0);

if ($productId <= 0 || $groupId <= 0) {
  header('Location: '.base_url('views/admin/items/index.php'));
  exit;
}

// Validate product belongs to tenant
$st = db()->prepare("SELECT id FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
$st->execute([':id'=>$productId, ':t'=>$tenantId]);
if (!$st->fetch()) {
  header('Location: '.base_url('views/admin/items/index.php')); exit;
}

// Validate group belongs to tenant
$sg = db()->prepare("SELECT id FROM variation_groups WHERE id=:gid AND tenant_id=:t LIMIT 1");
$sg->execute([':gid'=>$groupId, ':t'=>$tenantId]);
if (!$sg->fetch()) {
  header('Location: '.base_url('views/admin/items/variations.php?product_id='.$productId)); exit;
}

// Next sort
$sn = db()->prepare("SELECT COALESCE(MAX(sort_order),0)+1 AS next_so FROM product_variation_groups WHERE product_id=:pid");
$sn->execute([':pid'=>$productId]);
$next = (int)($sn->fetch()['next_so'] ?? 1);

try {
  $ins = db()->prepare("INSERT IGNORE INTO product_variation_groups (product_id, group_id, sort_order)
                        VALUES (:pid, :gid, :so)");
  $ins->execute([':pid'=>$productId, ':gid'=>$groupId, ':so'=>$next]);
} catch (Throwable $e) {
  // ignore; UI will reflect current state
}

header('Location: '.base_url('views/admin/items/variations.php?product_id='.$productId));