<?php
// controllers/items/branch_availability_save.php — bulk save branch availability & price overrides
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId   = tenant_id();
$productId  = (int)($_POST['product_id'] ?? 0);
$available  = $_POST['available']      ?? []; // checkbox array: available[branch_id] = 1
$overrides  = $_POST['price_override'] ?? []; // price_override[branch_id] = "12.345" | ""

if ($productId <= 0) {
  header('Location: ' . base_url('views/admin/items/index.php'));
  exit;
}

// Ensure product belongs to tenant
$st = db()->prepare("SELECT id FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
$st->execute([':id'=>$productId, ':t'=>$tenantId]);
if (!$st->fetch()) {
  header('Location: ' . base_url('views/admin/items/index.php'));
  exit;
}

// Load all tenant branches
$bst = db()->prepare("SELECT id FROM branches WHERE tenant_id=:t");
$bst->execute([':t'=>$tenantId]);
$branches = array_map(fn($r)=>(int)$r['id'], $bst->fetchAll(PDO::FETCH_ASSOC));

try {
  db()->beginTransaction();

  // Upsert or delete per branch
  $upsert = db()->prepare("
    INSERT INTO product_branch_availability (product_id, branch_id, is_available, price_override)
    VALUES (:pid, :bid, :av, :po)
    ON DUPLICATE KEY UPDATE is_available = VALUES(is_available),
                            price_override = VALUES(price_override)
  ");
  $del = db()->prepare("DELETE FROM product_branch_availability WHERE product_id=:pid AND branch_id=:bid");

  foreach ($branches as $bid) {
    $isAvail = isset($available[$bid]) ? 1 : 0;
    $price   = isset($overrides[$bid]) && $overrides[$bid] !== '' ? (float)$overrides[$bid] : null;

    // If not available AND no override row desired -> delete to keep table lean
    if ($isAvail === 0 && $price === null) {
      $del->execute([':pid'=>$productId, ':bid'=>$bid]);
      continue;
    }

    $upsert->execute([
      ':pid' => $productId,
      ':bid' => $bid,
      ':av'  => $isAvail,
      ':po'  => $price,
    ]);
  }

  db()->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  // Optional: set a flash message
}

header('Location: ' . base_url('views/admin/items/branch_availability.php?product_id=' . $productId));