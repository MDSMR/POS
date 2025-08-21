<?php
// public_html/controllers/admin/orders_refund.php
// Refund or void an order + revoke cashback voucher(s) earned on this order (tenant safe)
declare(strict_types=1);

/* Bootstrap */
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) { http_response_code(500); exit('Configuration file not found: /config/db.php'); }
require_once $bootstrap_path;
if (!function_exists('db') || !function_exists('use_backend_session')) { http_response_code(500); exit('Required functions missing in config/db.php (db(), use_backend_session()).'); }
use_backend_session();

/* Optional rewards include (for constants/types, not strictly required) */
$rewards_path = __DIR__ . '/../../includes/rewards.php';
if (is_file($rewards_path)) { require_once $rewards_path; }

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];
$userId   = (int)$user['id'];

/* Input */
$id       = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$mode     = trim((string)($_POST['mode'] ?? $_GET['mode'] ?? 'refund')); // 'refund' | 'void'
$return   = (string)($_POST['return'] ?? $_GET['return'] ?? '/views/admin/orders/index.php');

/* CSRF (if coming from a form that carries csrf_orders) */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (empty($_SESSION['csrf_orders']) || (($_POST['csrf'] ?? '') !== $_SESSION['csrf_orders'])) {
    $_SESSION['flash'] = 'Invalid request. Please try again.';
    header('Location: ' . $return); exit;
  }
}

/* Helpers */
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
    $q->execute([':t'=>$table, ':c'=>$col]); return (bool)$q->fetchColumn();
  } catch(Throwable $e){ return false; }
}

if ($id <= 0 || !in_array($mode, ['refund','void'], true)) {
  $_SESSION['flash'] = 'Invalid request.';
  header('Location: ' . $return); exit;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Load order & tenant guard
  $st = $pdo->prepare("SELECT id, tenant_id, status, payment_status FROM orders WHERE id = :id LIMIT 1");
  $st->execute([':id'=>$id]);
  $ord = $st->fetch();
  if (!$ord || (int)$ord['tenant_id'] !== $tenantId) {
    $_SESSION['flash'] = 'Order not found for this tenant.';
    header('Location: ' . $return); exit;
  }

  $has_closed_at   = column_exists($pdo,'orders','closed_at');
  $has_voided_at   = column_exists($pdo,'orders','voided_at');
  $has_voided_by   = column_exists($pdo,'orders','voided_by_user_id');
  $has_voided_bool = column_exists($pdo,'orders','is_voided');

  $pdo->beginTransaction();

  // 1) Flip order status/payment_status
  $sets = ["updated_at = NOW()"];
  $args = [':id'=>$id];

  if ($mode === 'refund') {
    $sets[] = "status = 'refunded'";
    $sets[] = "payment_status = 'voided'";
  } else { // void
    $sets[] = "status = 'voided'";
    $sets[] = "payment_status = 'voided'";
  }

  if ($has_voided_bool) {
    $sets[] = "is_voided = 1";
  }
  $sql = "UPDATE orders SET ".implode(', ', $sets)." WHERE id = :id LIMIT 1";
  $pdo->prepare($sql)->execute($args);

  if ($mode === 'void' && $has_voided_at) {
    $params = [':id'=>$id];
    $sqlV = "UPDATE orders SET voided_at = COALESCE(voided_at, NOW())";
    if ($has_voided_by) { $sqlV .= ", voided_by_user_id = COALESCE(voided_by_user_id, :vb)"; $params[':vb'] = $userId; }
    $sqlV .= " WHERE id = :id LIMIT 1";
    $pdo->prepare($sqlV)->execute($params);
  }
  if ($mode === 'refund' && $has_closed_at) {
    // If previously closed, keep closed_at; no change needed. Left as-is.
  }

  // 2) Revoke cashback(s) earned by this order
  //    - Find ledger entries for this order of type 'cashback_earn'
  //    - For each voucher_id: mark voucher 'void' and uses_remaining = 0 if present
  //    - Insert a reversing ledger row 'cashback_revoke' with -cash_delta
  $earnRows = [];
  try {
    $st = $pdo->prepare("SELECT id, voucher_id, cash_delta
                         FROM loyalty_ledger
                         WHERE tenant_id = :t AND order_id = :o AND type = 'cashback_earn'");
    $st->execute([':t'=>$tenantId, ':o'=>$id]);
    $earnRows = $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    // Ledger table may not exist in some installs—ignore gracefully
    $earnRows = [];
  }

  foreach ($earnRows as $er) {
    $voucherId = (int)($er['voucher_id'] ?? 0);
    $amt       = (float)($er['cash_delta'] ?? 0);

    // (a) Void voucher if exists
    if ($voucherId > 0) {
      try {
        $pdo->prepare("UPDATE vouchers
                       SET status = 'void', uses_remaining = 0, updated_at = NOW()
                       WHERE tenant_id = :t AND id = :vid LIMIT 1")
            ->execute([':t'=>$tenantId, ':vid'=>$voucherId]);
      } catch (Throwable $e) { /* ignore */ }
    }

    // (b) Insert reversing ledger
    try {
      $pdo->prepare("INSERT INTO loyalty_ledger
          (tenant_id, program_id, customer_id, order_id, type, cash_delta, voucher_id, expires_at, reason, created_at)
        SELECT
          ll.tenant_id, ll.program_id, ll.customer_id, ll.order_id,
          'cashback_revoke', :negamt, ll.voucher_id, ll.expires_at, 'refund_or_void', NOW()
        FROM loyalty_ledger ll
        WHERE ll.id = :srcid
        LIMIT 1")
        ->execute([':negamt' => -abs($amt), ':srcid' => (int)$er['id']]);
    } catch (Throwable $e) { /* ignore */ }
  }

  $pdo->commit();
  $_SESSION['flash'] = ($mode === 'refund') ? 'Order refunded and cashback revoked.' : 'Order voided and cashback revoked.';
  header('Location: ' . $return);
  exit;

} catch (Throwable $e) {
  if (!empty($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash'] = 'Operation failed. ' . $e->getMessage();
  header('Location: ' . $return);
  exit;
}