<?php
// controllers/admin/orders_save.php — Create/Update Order
declare(strict_types=1);

$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) { http_response_code(500); exit('Configuration file not found'); }
require_once $bootstrap_path;
if (!function_exists('db') || !function_exists('use_backend_session')) { http_response_code(500); exit('Required functions missing'); }
use_backend_session();

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];
$uid = (int)$user['id'];

/* CSRF */
if (empty($_SESSION['csrf_orders']) || ($_POST['csrf'] ?? '') !== $_SESSION['csrf_orders']) {
  $_SESSION['flash']='Invalid request.'; header('Location:/views/admin/orders.php'); exit;
}

/* Helpers */
function strv($k){ return trim((string)($_POST[$k] ?? '')); }
function numv($k){ $s=trim((string)($_POST[$k] ?? '')); if($s==='') return null; return (float)preg_replace('/[^\d.\-]/','',$s); }
function intval_or_null($k){ $s=trim((string)($_POST[$k] ?? '')); return ($s==='')?null:(int)$s; }
function ensure_enum(string $val, array $allowed, string $default){ return in_array($val,$allowed,true)?$val:$default; }

/* Read */
$id = (int)($_POST['id'] ?? 0);
$branch_id = (int)($_POST['branch_id'] ?? 0);
$order_type = ensure_enum(strv('order_type'),
  ['dine_in','takeaway','delivery','pickup','online','aggregator','talabat','room','other'],'dine_in');
$status = ensure_enum(strv('status'),
  ['open','held','sent','preparing','ready','served','closed','voided','refunded'],'open');
$payment_status = ensure_enum(strv('payment_status'),
  ['unpaid','partial','paid','refunded','voided'],'unpaid');

$customer_id = intval_or_null('customer_id');
$customer_name = strv('customer_name');
$table_id = intval_or_null('table_id');
$session_id = intval_or_null('session_id');
$source_channel = ensure_enum(strv('source_channel'), ['pos','online','aggregator'], 'pos');

$receipt_reference = strv('receipt_reference');
$order_notes = strv('order_notes');

$subtotal = numv('subtotal_amount') ?? 0.0;
$discount = numv('discount_amount') ?? 0.0;
$tax_percent = numv('tax_percent') ?? 0.0;
$service_percent = numv('service_percent') ?? 0.0;

/* Compute dependent amounts (simple model; adjust to your tax rules) */
$tax_amount = round(($subtotal - $discount) * ($tax_percent/100), 3);
$service_amount = round(($subtotal - $discount) * ($service_percent/100), 3);

/* total = subtotal - discount + tax + service + commissions (commissions are updated elsewhere; keep current value) */
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

/* Load current commission_total_amount if updating, else 0 */
$commission_total_amount = 0.0;
if ($id > 0) {
  $chk = $pdo->prepare("SELECT commission_total_amount FROM orders WHERE id=:id AND tenant_id=:t LIMIT 1");
  $chk->execute([':id'=>$id, ':t'=>$tenantId]);
  $commission_total_amount = (float)($chk->fetchColumn() ?: 0);
}

$total_amount = round(($subtotal - $discount) + $tax_amount + $service_amount + $commission_total_amount, 3);

/* Transition timestamps */
$is_voided = ($status==='voided') ? 1 : 0;
$closed_at = null; $voided_at = null; $voided_by_user_id = null;

if ($status==='closed') { $closed_at = date('Y-m-d H:i:s'); }
if ($status==='voided') { $voided_at = date('Y-m-d H:i:s'); $voided_by_user_id = $uid; if($payment_status!=='refunded'){ $payment_status='voided'; } }

try {
  $pdo->beginTransaction();

  if ($id > 0) {
    // ensure tenant
    $chk=$pdo->prepare("SELECT id FROM orders WHERE id=:id AND tenant_id=:t LIMIT 1");
    $chk->execute([':id'=>$id, ':t'=>$tenantId]);
    if(!$chk->fetchColumn()){ throw new RuntimeException('Order not found for this tenant'); }

    $sql="
      UPDATE orders SET
        branch_id=:branch_id,
        customer_id=:customer_id,
        customer_name=:customer_name,
        table_id=:table_id,
        session_id=:session_id,
        order_type=:order_type,
        status=:status,
        payment_status=:payment_status,
        source_channel=:source_channel,
        receipt_reference=:receipt_reference,
        order_notes=:order_notes,
        subtotal_amount=:subtotal,
        discount_amount=:discount,
        tax_percent=:tax_percent,
        tax_amount=:tax_amount,
        service_percent=:service_percent,
        service_amount=:service_amount,
        total_amount=:total_amount,
        is_voided=:is_voided,
        closed_at = CASE WHEN :closed_at IS NULL THEN closed_at ELSE :closed_at END,
        voided_at = CASE WHEN :voided_at IS NULL THEN voided_at ELSE :voided_at END,
        voided_by_user_id = CASE WHEN :voided_by IS NULL THEN voided_by_user_id ELSE :voided_by END,
        updated_at=NOW()
      WHERE id=:id AND tenant_id=:t
      LIMIT 1
    ";
    $st=$pdo->prepare($sql);
    $st->execute([
      ':branch_id'=>$branch_id,
      ':customer_id'=>$customer_id,
      ':customer_name'=>$customer_name,
      ':table_id'=>$table_id,
      ':session_id'=>$session_id,
      ':order_type'=>$order_type,
      ':status'=>$status,
      ':payment_status'=>$payment_status,
      ':source_channel'=>$source_channel,
      ':receipt_reference'=>$receipt_reference,
      ':order_notes'=>$order_notes,
      ':subtotal'=>$subtotal,
      ':discount'=>$discount,
      ':tax_percent'=>$tax_percent,
      ':tax_amount'=>$tax_amount,
      ':service_percent'=>$service_percent,
      ':service_amount'=>$service_amount,
      ':total_amount'=>$total_amount,
      ':is_voided'=>$is_voided,
      ':closed_at'=>$closed_at,
      ':voided_at'=>$voided_at,
      ':voided_by'=>$voided_by_user_id,
      ':id'=>$id, ':t'=>$tenantId,
    ]);
  } else {
    $sql="
      INSERT INTO orders
        (tenant_id, branch_id, created_by_user_id, customer_id, customer_name, table_id, session_id,
         order_type, status, payment_status, source_channel, receipt_reference, order_notes,
         subtotal_amount, discount_amount, tax_percent, tax_amount, service_percent, service_amount,
         commission_total_amount, total_amount, is_voided, created_at, updated_at)
      VALUES
        (:tenant_id, :branch_id, :uid, :customer_id, :customer_name, :table_id, :session_id,
         :order_type, :status, :payment_status, :source_channel, :receipt_reference, :order_notes,
         :subtotal, :discount, :tax_percent, :tax_amount, :service_percent, :service_amount,
         :comm_total, :total_amount, :is_voided, NOW(), NOW())
    ";
    $st=$pdo->prepare($sql);
    $st->execute([
      ':tenant_id'=>$tenantId,
      ':branch_id'=>$branch_id,
      ':uid'=>$uid,
      ':customer_id'=>$customer_id,
      ':customer_name'=>$customer_name,
      ':table_id'=>$table_id,
      ':session_id'=>$session_id,
      ':order_type'=>$order_type,
      ':status'=>$status,
      ':payment_status'=>$payment_status,
      ':source_channel'=>$source_channel,
      ':receipt_reference'=>$receipt_reference,
      ':order_notes'=>$order_notes,
      ':subtotal'=>$subtotal,
      ':discount'=>$discount,
      ':tax_percent'=>$tax_percent,
      ':tax_amount'=>$tax_amount,
      ':service_percent'=>$service_percent,
      ':service_amount'=>$service_amount,
      ':comm_total'=>$commission_total_amount,
      ':total_amount'=>$total_amount,
      ':is_voided'=>$is_voided
    ]);
    $id = (int)$pdo->lastInsertId();

    // apply timestamps if closed/voided on create
    if ($status==='closed'){
      $pdo->prepare("UPDATE orders SET closed_at=NOW() WHERE id=:id")->execute([':id'=>$id]);
    } elseif ($status==='voided'){
      $pdo->prepare("UPDATE orders SET voided_at=NOW(), voided_by_user_id=:u WHERE id=:id")->execute([':u'=>$uid, ':id'=>$id]);
    }
  }

  $pdo->commit();
  $_SESSION['flash']='Order saved.';
  header('Location: /views/admin/order_view.php?id='.$id);
  exit;

} catch(Throwable $e){
  if(!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash']='Save error. '.$e->getMessage();
  header('Location: '.($id>0 ? '/views/admin/order_edit.php?id='.$id : '/views/admin/order_add.php'));
  exit;
}