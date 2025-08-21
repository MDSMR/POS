<?php
// controllers/orders/export_csv.php — export filtered orders to CSV
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId = tenant_id();

// Read same filters as index
$branchId   = (int)($_GET['branch_id'] ?? 0);
$dateFrom   = $_GET['from'] ?? date('Y-m-d');
$dateTo     = $_GET['to']   ?? date('Y-m-d');
$status     = $_GET['status'] ?? '';
$orderType  = $_GET['order_type'] ?? '';
$aggregator = (int)($_GET['aggregator_id'] ?? 0);
$q          = trim($_GET['q'] ?? '');

$where = ["o.tenant_id = :t", "DATE(o.created_at) BETWEEN :df AND :dt"];
$params = [':t'=>$tenantId, ':df'=>$dateFrom, ':dt'=>$dateTo];

if ($branchId > 0) { $where[] = "o.branch_id = :b"; $params[':b'] = $branchId; }
if ($status !== '') { $where[] = "o.status = :s"; $params[':s'] = $status; }
if ($orderType !== '') { $where[] = "o.order_type = :ot"; $params[':ot'] = $orderType; }
if ($aggregator > 0) { $where[] = "o.aggregator_id = :aid"; $params[':aid'] = $aggregator; }
if ($q !== '') {
  $where[] = "(o.customer_name LIKE :q OR o.id = :qid OR EXISTS(
                SELECT 1 FROM dining_tables dt WHERE dt.id = o.table_id AND dt.table_number LIKE :q
              ))";
  $params[':q'] = "%$q%";
  $params[':qid'] = ctype_digit($q) ? (int)$q : -1;
}
$whereSql = implode(' AND ', $where);

$sql = "
  SELECT
    o.id, o.created_at, b.name AS branch, o.order_type, o.status, dt.table_number,
    o.guest_count, o.customer_name, a.name AS aggregator,
    o.subtotal_amount, o.discount_amount, o.tax_amount, o.service_amount, o.total_amount,
    o.commission_amount
  FROM orders o
  LEFT JOIN branches b ON b.id = o.branch_id
  LEFT JOIN aggregators a ON a.id = o.aggregator_id
  LEFT JOIN dining_tables dt ON dt.id = o.table_id
  WHERE $whereSql
  ORDER BY o.created_at DESC, o.id DESC
  LIMIT 5000
";
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

// Output CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="orders_export_'.date('Ymd_His').'.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, array_keys($rows[0] ?? [
  'id','created_at','branch','order_type','status','table_number','guest_count','customer_name',
  'aggregator','subtotal_amount','discount_amount','tax_amount','service_amount','total_amount','commission_amount'
]));
foreach ($rows as $r) {
  fputcsv($out, $r);
}
fclose($out);
exit;