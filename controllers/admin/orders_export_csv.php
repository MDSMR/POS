<?php
// public_html/controllers/admin/orders_export_csv.php
// Export Orders list to CSV using same filters as the index page (tenant-scoped)
declare(strict_types=1);

/* Bootstrap */
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) { http_response_code(500); exit('Configuration file not found: /config/db.php'); }
require_once $bootstrap_path;
if (!function_exists('db') || !function_exists('use_backend_session')) { http_response_code(500); exit('Required functions missing in config/db.php (db(), use_backend_session()).'); }
use_backend_session();

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];

/* Helpers */
function s($v){ return trim((string)$v); }

/* Filters (same as index) */
$branchId   = (int)($_GET['branch_id'] ?? 0);
$dateFrom   = $_GET['from'] ?? date('Y-m-d');
$dateTo     = $_GET['to']   ?? date('Y-m-d');
$status     = s($_GET['status'] ?? '');
$orderType  = s($_GET['order_type'] ?? '');
$aggregator = (int)($_GET['aggregator_id'] ?? 0);
$q          = trim($_GET['q'] ?? '');

$allowed_status = ['','open','held','sent','preparing','ready','served','closed','voided','cancelled','refunded'];
$allowed_types  = ['','dine_in','takeaway','delivery'];
if (!in_array($status, $allowed_status, true))   { $status = ''; }
if (!in_array($orderType, $allowed_types, true)) { $orderType = ''; }

/* Build WHERE */
$where   = ["o.tenant_id = :t", "DATE(o.created_at) BETWEEN :df AND :dt"];
$params  = [':t'=>$tenantId, ':df'=>$dateFrom, ':dt'=>$dateTo];

if ($branchId > 0)      { $where[] = "o.branch_id = :b";    $params[':b']   = $branchId; }
if ($status !== '')     { $where[] = "o.status = :s";       $params[':s']   = $status; }
if ($orderType !== '')  { $where[] = "o.order_type = :ot";  $params[':ot']  = $orderType; }
if ($aggregator > 0)    { $where[] = "o.aggregator_id = :a";$params[':a']   = $aggregator; }
if ($q !== '') {
  $where[] = "(o.customer_name LIKE :q OR o.id = :qid OR EXISTS(
                SELECT 1 FROM dining_tables dt WHERE dt.id = o.table_id AND dt.table_number LIKE :q
              ))";
  $params[':q']  = "%$q%";
  $params[':qid']= ctype_digit($q) ? (int)$q : -1;
}
$whereSql = implode(' AND ', $where);

/* Query */
$sql = "
  SELECT
    o.id, o.created_at, o.updated_at, o.closed_at,
    o.branch_id, b.name AS branch_name,
    o.order_type, o.table_id, dt.table_number,
    o.customer_name, o.guest_count,
    o.status, o.payment_status, o.payment_method, o.session_id, o.source_channel,
    o.aggregator_id, a.name AS aggregator_name,
    o.receipt_reference, o.external_order_reference, o.order_notes,
    o.subtotal_amount, o.discount_amount, o.tax_percent, o.tax_amount,
    o.service_percent, o.service_amount, o.commission_total_amount, o.total_amount
  FROM orders o
  LEFT JOIN branches b     ON b.id = o.branch_id
  LEFT JOIN aggregators a  ON a.id = o.aggregator_id
  LEFT JOIN dining_tables dt ON dt.id = o.table_id
  WHERE $whereSql
  ORDER BY o.created_at DESC, o.id DESC
";

try {
  $pdo = db();
  $st  = $pdo->prepare($sql);
  foreach ($params as $k=>$v) { $st->bindValue($k, $v); }
  $st->execute();
  $rows = $st->fetchAll() ?: [];
} catch (Throwable $e) {
  $_SESSION['flash'] = 'Export failed: ' . $e->getMessage();
  header('Location: /views/admin/orders/index.php'); exit;
}

/* Output CSV */
$filename = 'orders_export_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$filename.'"');
header('Pragma: no-cache');
header('Expires: 0');

// Optional BOM for Excel friendliness
echo "\xEF\xBB\xBF";

$fp = fopen('php://output', 'w');

// Header
fputcsv($fp, [
  'ID','Created At','Updated At','Closed At',
  'Branch ID','Branch Name',
  'Order Type','Table ID','Table Number',
  'Customer Name','Guest Count',
  'Status','Payment Status','Payment Method','POS Session','Source Channel',
  'Aggregator ID','Aggregator Name',
  'Receipt Reference','External Reference','Order Notes',
  'Subtotal','Discount','Tax %','Tax Amount',
  'Service %','Service Amount','Commission Total','Total'
]);

// Rows
foreach ($rows as $r) {
  fputcsv($fp, [
    (int)$r['id'],
    (string)$r['created_at'],
    (string)$r['updated_at'],
    (string)$r['closed_at'],
    (int)$r['branch_id'],
    (string)($r['branch_name'] ?? ''),
    (string)$r['order_type'],
    (int)($r['table_id'] ?? 0),
    (string)($r['table_number'] ?? ''),
    (string)($r['customer_name'] ?? ''),
    (int)($r['guest_count'] ?? 0),
    (string)$r['status'],
    (string)($r['payment_status'] ?? ''),
    (string)($r['payment_method'] ?? ''),
    (string)($r['session_id'] ?? ''),
    (string)($r['source_channel'] ?? 'pos'),
    (int)($r['aggregator_id'] ?? 0),
    (string)($r['aggregator_name'] ?? ''),
    (string)($r['receipt_reference'] ?? ''),
    (string)($r['external_order_reference'] ?? ''),
    (string)($r['order_notes'] ?? ''),
    number_format((float)$r['subtotal_amount'], 3, '.', ''),
    number_format((float)$r['discount_amount'], 3, '.', ''),
    number_format((float)$r['tax_percent'], 2, '.', ''),
    number_format((float)$r['tax_amount'], 3, '.', ''),
    number_format((float)$r['service_percent'], 2, '.', ''),
    number_format((float)$r['service_amount'], 3, '.', ''),
    number_format((float)$r['commission_total_amount'], 3, '.', ''),
    number_format((float)$r['total_amount'], 3, '.', ''),
  ]);
}

fclose($fp);
exit;