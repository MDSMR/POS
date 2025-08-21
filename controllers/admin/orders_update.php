<?php
// public_html/controllers/admin/orders_update.php
// Update an existing order (aligned with Add/Edit/View pages) + transition detection (+ optional rewards hook)
declare(strict_types=1);

/* Bootstrap */
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) { http_response_code(500); exit('Configuration file not found: /config/db.php'); }
require_once $bootstrap_path;
if (!function_exists('db') || !function_exists('use_backend_session')) { http_response_code(500); exit('Required functions missing in config/db.php (db(), use_backend_session()).'); }
use_backend_session();

/*
 | (Optional) Rewards include — safe if missing
 | If you have /includes/rewards.php (from the rewards design we discussed),
 | this will be used to issue cashback on transition to closed+paid.
*/
$rewards_available = false;
$rewards_path = __DIR__ . '/../../includes/rewards.php';
if (is_file($rewards_path)) {
    require_once $rewards_path; // defines rewards_issue_cashback_for_order(...)
    if (function_exists('rewards_issue_cashback_for_order')) {
        $rewards_available = true;
    }
}

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];
$userId   = (int)$user['id'];

/* CSRF */
if (empty($_SESSION['csrf_orders']) || (($_POST['csrf'] ?? '') !== $_SESSION['csrf_orders'])) {
    $_SESSION['flash'] = 'Invalid request. Please try again.';
    header('Location: /views/admin/orders.php'); exit;
}

/* Helpers */
function s($v){ return trim((string)$v); }
function i_or_null($v){ $v = trim((string)$v); return ($v==='') ? null : (int)$v; }
function f($v){
    // Accept numbers possibly formatted with currency symbols
    $n = preg_replace('/[^\d.\-]/','',(string)$v);
    if ($n === '' || !is_numeric($n)) return 0.0;
    return (float)$n;
}
function round2($n){ return (float)number_format((float)$n, 2, '.', ''); }
function column_exists(PDO $pdo, string $t, string $c): bool {
    try {
        $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $q->execute([':t'=>$t, ':c'=>$c]);
        return (bool)$q->fetchColumn();
    } catch(Throwable $e){ return false; }
}

/* Input */
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash'] = 'Order not specified.';
    header('Location: /views/admin/orders.php'); exit;
}

$branch_id       = max(0, (int)($_POST['branch_id'] ?? 0));
$order_type      = s($_POST['order_type'] ?? 'dine_in');           // enum: dine_in, takeaway, delivery
$aggregator_id   = i_or_null($_POST['aggregator_id'] ?? '');
$ext_ref         = s($_POST['external_order_reference'] ?? '');
$customer_name   = s($_POST['customer_name'] ?? '');
$guest_count_raw = s($_POST['guest_count'] ?? '');
$guest_count     = ($guest_count_raw === '') ? null : max(0, (int)$guest_count_raw);

$receipt_reference = s($_POST['receipt_reference'] ?? '');
$order_notes       = (string)($_POST['order_notes'] ?? '');
$session_id        = i_or_null($_POST['session_id'] ?? '');

$status_new         = s($_POST['status'] ?? 'open');               // open, closed, cancelled, ...
$payment_status_new = s($_POST['payment_status'] ?? 'unpaid');     // unpaid, paid, voided
$payment_method     = s($_POST['payment_method'] ?? '');           // '', cash, card, wallet
$source_channel     = 'pos';                                       // backend-only now

// Amounts (client provided; we recompute authoritative totals below)
$subtotal_amount  = max(0.0, f($_POST['subtotal_amount'] ?? 0));
$discount_amount  = max(0.0, f($_POST['discount_amount'] ?? 0));
$tax_percent      = max(0.0, f($_POST['tax_percent'] ?? 0));
$service_percent  = max(0.0, f($_POST['service_percent'] ?? 0));
$commission_total = max(0.0, f($_POST['commission_total_amount'] ?? 0)); // aggregator/service commissions

/* Validate enums */
$allowed_types  = ['dine_in','takeaway','delivery'];
$allowed_status = ['open','held','sent','preparing','ready','served','closed','voided','cancelled','refunded'];
$allowed_pay    = ['unpaid','paid','voided'];
$allowed_pm     = ['', 'cash','card','wallet'];

if (!in_array($order_type, $allowed_types, true))       { $order_type = 'dine_in'; }
if (!in_array($status_new, $allowed_status, true))      { $status_new = 'open'; }
if (!in_array($payment_status_new, $allowed_pay, true)) { $payment_status_new = 'unpaid'; }
if (!in_array($payment_method, $allowed_pm, true))      { $payment_method = ''; }

/* Delivery-specific cleanup */
if ($order_type !== 'delivery') {
    $aggregator_id = null;
    $ext_ref = '';
}

/* Recalculate server-side amounts (subtotal - discount as base) */
$base            = max($subtotal_amount - $discount_amount, 0.0);
$tax_amount      = round2(($tax_percent / 100.0) * $base);
$service_amount  = round2(($service_percent / 100.0) * $base);
$total_amount    = round2($base + $tax_amount + $service_amount + $commission_total);

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    /* Guard + load previous state (for transition detection) */
    $chk = $pdo->prepare("SELECT tenant_id, status, payment_status FROM orders WHERE id = :id LIMIT 1");
    $chk->execute([':id'=>$id]);
    $row = $chk->fetch();
    if (!$row || (int)$row['tenant_id'] !== $tenantId) {
        $_SESSION['flash'] = 'Order not found for this tenant.';
        header('Location: /views/admin/orders.php'); exit;
    }
    $status_prev         = (string)$row['status'];
    $payment_status_prev = (string)$row['payment_status'];

    /* Probe optional columns for portability */
    $has_receipt     = column_exists($pdo,'orders','receipt_reference');
    $has_session     = column_exists($pdo,'orders','session_id');
    $has_notes       = column_exists($pdo,'orders','order_notes');
    $has_channel     = column_exists($pdo,'orders','source_channel');
    $has_voided_bool = column_exists($pdo,'orders','is_voided');
    $has_closed_at   = column_exists($pdo,'orders','closed_at');
    $has_voided_at   = column_exists($pdo,'orders','voided_at');
    $has_voided_by   = column_exists($pdo,'orders','voided_by_user_id');
    $has_ext_ref     = column_exists($pdo,'orders','external_order_reference');
    $has_agg         = column_exists($pdo,'orders','aggregator_id');

    $sets = [
        "branch_id = :branch_id",
        "order_type = :otype",
        "customer_name = :cname",
        "guest_count = :gcount",
        "status = :status",
        "payment_status = :pstatus",
        "payment_method = :pmethod",

        "subtotal_amount = :subtotal",
        "discount_amount = :discount",
        "tax_percent = :taxp",
        "tax_amount = :taxa",
        "service_percent = :servp",
        "service_amount = :serva",
        "commission_total_amount = :comm",
        "total_amount = :total",

        "updated_at = NOW()"
    ];

    $args = [
        ':branch_id' => $branch_id,
        ':otype'     => $order_type,
        ':cname'     => $customer_name,
        ':gcount'    => $guest_count,
        ':status'    => $status_new,
        ':pstatus'   => $payment_status_new,
        ':pmethod'   => $payment_method,

        ':subtotal'  => $subtotal_amount,
        ':discount'  => $discount_amount,
        ':taxp'      => $tax_percent,
        ':taxa'      => $tax_amount,
        ':servp'     => $service_percent,
        ':serva'     => $service_amount,
        ':comm'      => $commission_total,
        ':total'     => $total_amount,

        ':id'        => $id
    ];

    if ($has_agg) {
        $sets[]       = "aggregator_id = :agg";
        $args[':agg'] = $aggregator_id;
    }
    if ($has_ext_ref) {
        $sets[]          = "external_order_reference = :extref";
        $args[':extref'] = ($order_type === 'delivery') ? ($ext_ref ?: null) : null;
    }
    if ($has_receipt) {
        $sets[]        = "receipt_reference = :rref";
        $args[':rref'] = ($receipt_reference !== '') ? $receipt_reference : null;
    }
    if ($has_notes) {
        $sets[]        = "order_notes = :notes";
        $args[':notes'] = $order_notes;
    }
    if ($has_session) {
        $sets[]        = "session_id = :sess";
        $args[':sess'] = $session_id;
    }
    if ($has_channel) {
        $sets[]        = "source_channel = :sch";
        $args[':sch']  = $source_channel; // POS only for now
    }
    if ($has_voided_bool) {
        $sets[]        = "is_voided = :isv";
        $args[':isv']  = ($status_new === 'voided' || $payment_status_new === 'voided') ? 1 : 0;
    }

    $sql = "UPDATE orders SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
    $pdo->prepare($sql)->execute($args);

    /* Lifecycle timestamps */
    if ($status_new === 'closed' && $has_closed_at) {
        $pdo->prepare("UPDATE orders SET closed_at = COALESCE(closed_at, NOW()) WHERE id = :id LIMIT 1")
            ->execute([':id'=>$id]);
    }
    if ($status_new === 'voided') {
        if ($has_voided_at) {
            $sqlV = "UPDATE orders SET voided_at = COALESCE(voided_at, NOW())"
                  . ($has_voided_by ? ", voided_by_user_id = COALESCE(voided_by_user_id, :vb)" : "")
                  . " WHERE id = :id LIMIT 1";
            $params = [':id'=>$id];
            if ($has_voided_by) { $params[':vb'] = $userId; }
            $pdo->prepare($sqlV)->execute($params);
        }
    }

    /*
     | Transition detection & optional rewards trigger
     | Fire only when the order *became* closed and/or *became* paid.
    */
    $becameClosed = ($status_prev !== 'closed' && $status_new === 'closed');
    $becamePaid   = ($payment_status_prev !== 'paid' && $payment_status_new === 'paid');

    if ($rewards_available && ($becameClosed || $becamePaid)) {
        // Try to issue cashback once; ignore failures silently for now
        try {
            $res = rewards_issue_cashback_for_order($tenantId, $id);
            if (!empty($res['issued'])) {
                $_SESSION['flash'] = 'Order updated. Cashback issued: ' . $res['amount'] . ' (Voucher ' . $res['code'] . ').';
            } else {
                $_SESSION['flash'] = 'Order updated.';
            }
        } catch (Throwable $e) {
            // Do not block the order update on rewards errors
            $_SESSION['flash'] = 'Order updated.';
        }
    } else {
        $_SESSION['flash'] = 'Order updated.';
    }

    header('Location: /views/admin/order_view.php?id=' . $id);
    exit;

} catch (Throwable $e) {
    $_SESSION['flash'] = 'Update error. ' . $e->getMessage();
    header('Location: /views/admin/order_edit.php?id=' . $id);
    exit;
}