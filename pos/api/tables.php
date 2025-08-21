<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';

pos_require_user();

$tenantId = tenant_id();
$branchId = (int)($_GET['branch_id'] ?? 1);

$stmt = db()->prepare("
  SELECT id, table_number, seats, status
  FROM dining_tables
  WHERE tenant_id = :tenant_id AND branch_id = :branch_id
  ORDER BY CAST(table_number AS UNSIGNED), table_number
");
$stmt->execute([':tenant_id' => $tenantId, ':branch_id' => $branchId]);

json_out(['ok' => true, 'tables' => $stmt->fetchAll()]);