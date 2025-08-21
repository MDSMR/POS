<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';

pos_require_user();

$tenantId = tenant_id();
$branchId = (int)($_GET['branch_id'] ?? 1);
$q = trim($_GET['q'] ?? '');
$catId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

$sql = "
SELECT
  p.id,
  p.name_en,
  p.name_ar,
  COALESCE(pba.price_override, p.price)   AS price,
  p.pos_visible,
  p.is_active,
  COALESCE(pba.is_available, 1)           AS is_available
FROM products p
LEFT JOIN product_branch_availability pba
  ON pba.product_id = p.id AND pba.branch_id = :branch_id
";
$where = ["p.tenant_id = :tenant_id", "p.pos_visible = 1", "p.is_active = 1"];

$params = [
  ':tenant_id' => $tenantId,
  ':branch_id' => $branchId,
];

if ($q !== '') {
  $where[] = "(p.name_en LIKE :q OR p.name_ar LIKE :q)";
  $params[':q'] = '%' . $q . '%';
}

if ($catId) {
  $sql .= " INNER JOIN product_categories pc ON pc.product_id = p.id AND pc.category_id = :cat_id ";
  $params[':cat_id'] = $catId;
}

$sql .= " WHERE " . implode(' AND ', $where) . " ORDER BY p.name_en ASC LIMIT 500";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

json_out(['ok' => true, 'items' => $items, 'branch_id' => $branchId]);