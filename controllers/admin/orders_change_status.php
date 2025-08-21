<?php
// public_html/controllers/admin/orders_change_status.php
// Lightweight endpoint to change order.status from the Orders list (with tenant guard & lifecycle stamps)
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
$userId   = (int)$user['id'];

/* Input */
$id     = (int)($_GET['id'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$return = (string)($_GET['return'] ?? '/views/admin/orders/index.php');

/* Validate */
$allowed_status = ['open','held','sent','preparing','ready','served','closed','voided','cancelled','refunded'];
if ($id <= 0 || !in_array($status, $allowed_status, true)) {
  $_SESSION['flash'] = 'Invalid request.';
  header('Location: ' . $return); exit;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Load order & tenant guard
  $st = $pdo->prepare("SELECT id, tenant_id, status, payment_status FROM orders WHERE id = :id LIMIT 1");
  $st->execute([':id'=>$id]);
  $row = $st->fetch();
  if (!$row || (int)$row['tenant_id'] !== $tenantId) {
    $_SESSION['flash'] = 'Order not found for this tenant.';
    header('Location: ' . $return); exit;
  }

  $prevStatus = (string)$row['status'];
  if ($prevStatus === $status) {
    $_SESSION['flash'] = 'Status unchanged.';
    header('Location: ' . $return); exit;
  }

  // Probe optional columns
  $has_closed_at   = column_exists($pdo,'orders','closed_at');
  $has_voided_at   = column_exists($pdo,'orders','voided_at');
  $has_voided_by   = column_exists($pdo,'orders','voided_by_user_id');
  $has_voided_bool = column_exists($pdo,'orders','is_voided');

  // Apply status change
  $sets = ["status = :s", "updated_at = NOW()"];
  $args = [':s'=>$status, ':id'=>$id];

  if ($has_voided_bool) {
    $sets[] = "is_voided = :isv";
    $args[':isv'] = ($status === 'voided') ? 1 : 0;
  }

  $sql = "UPDATE orders SET ".implode(', ', $sets)." WHERE id = :id LIMIT 1";
  $pdo->prepare($sql)->execute($args);

  // Lifecycle stamps
  if ($status === 'closed' && $has_closed_at) {
    $pdo->prepare("UPDATE orders SET closed_at = COALESCE(closed_at, NOW()) WHERE id = :id LIMIT 1")->execute([':id'=>$id]);
  }
  if ($status === 'voided') {
    $params = [':id'=>$id];
    $sqlV = "UPDATE orders SET voided_at = COALESCE(voided_at, NOW())";
    if ($has_voided_by) { $sqlV .= ", voided_by_user_id = COALESCE(voided_by_user_id, :vb)"; $params[':vb'] = $userId; }
    $sqlV .= " WHERE id = :id LIMIT 1";
    if ($has_voided_at) { $pdo->prepare($sqlV)->execute($params); }
  }

  // Default flash
  $flashMsg = 'Order status updated to ' . ucfirst($status) . '.';

  // === Rewards hook: apply only when the order transitions to CLOSED ===
  if ($status === 'closed') {
    // Reusable function (no output) lives here:
    // public_html/controllers/admin/rewards/engine/_apply_on_closed.php
    $applyPath = __DIR__ . '/rewards/engine/_apply_on_closed.php';
    if (is_file($applyPath)) {
      require_once $applyPath;
      if (function_exists('rewards_apply_on_order_closed')) {
        try {
          // Isolate rewards work in its own short transaction
          $pdo->beginTransaction();
          $out = rewards_apply_on_order_closed($pdo, $tenantId, $id);
          $pdo->commit();

          if (!empty($out['notes'])) {
            $flashMsg = 'Order closed. Rewards: ' . implode('; ', array_map('strval', $out['notes']));
          } else {
            $flashMsg = 'Order closed. Rewards processed.';
          }
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          // Don’t block user flow; log and keep going
          error_log('[rewards] apply failed for order '.$id.': '.$e->getMessage());
          $flashMsg = 'Order closed. Rewards processing deferred.';
        }
      } else {
        // Function missing — keep original behavior
        error_log('[rewards] function rewards_apply_on_order_closed() not found.');
      }
    } else {
      // File missing — keep original behavior
      error_log('[rewards] _apply_on_closed.php not found at '.$applyPath);
    }
  }

  $_SESSION['flash'] = $flashMsg;
  header('Location: ' . $return);
  exit;

} catch (Throwable $e) {
  $_SESSION['flash'] = 'Update error. ' . $e->getMessage();
  header('Location: ' . $return);
  exit;
}

/**
 * Local copy of column_exists (kept private here to avoid extra includes)
 */
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
    $q->execute([':t'=>$table, ':c'=>$col]);
    return (bool)$q->fetchColumn();
  } catch (Throwable $e) { return false; }
}