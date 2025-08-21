<?php
// public_html/controllers/admin/orders/recalculate.php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
function base_url(string $p=''): string {
  $sch = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
  return rtrim($sch.'://'.$host, '/').'/'.ltrim($p,'/');
}
function admin_user(){ return $_SESSION['user'] ?? null; }
function admin_require(){
  $u = admin_user();
  $ok = $u && !empty($u['role_key']) && in_array($u['role_key'], ['admin','manager','pos_manager'], true);
  if (!$ok) { http_response_code(403); echo "Forbidden"; exit; }
}
admin_require();

require_once __DIR__ . '/../../../config/db.php';
if (!function_exists('db')) { function db(): PDO { global $pdo; return $pdo; } }

$orderId = (int)($_POST['id'] ?? 0);
if ($orderId <= 0) { header('Location: '.base_url('views/admin/orders/index.php?err=Missing+order+id')); exit; }

// Load order
$st = db()->prepare("SELECT * FROM orders WHERE id=:id LIMIT 1");
$st->execute([':id'=>$orderId]);
$o = $st->fetch(PDO::FETCH_ASSOC);
if (!$o) { header('Location: '.base_url('views/admin/orders/index.php?err=Order+not+found')); exit; }

$tenantId = (int)$o['tenant_id'];
$branchId = (int)$o['branch_id'];
$aggId    = $o['aggregator_id'] !== null ? (int)$o['aggregator_id'] : null;

// Subtotal from items
$sti = db()->prepare("SELECT SUM(line_subtotal) FROM order_items WHERE order_id = :id");
$sti->execute([':id'=>$orderId]);
$subtotal = (float)($sti->fetchColumn() ?: 0.0);

// Promo discounts from rows
$stp = db()->prepare("SELECT SUM(amount_applied) FROM order_discounts WHERE order_id = :id");
$stp->execute([':id'=>$orderId]);
$promoSum = (float)($stp->fetchColumn() ?: 0.0);

// Manual part = existing order discount minus promo rows (never below 0)
$manualPart = max(0.0, (float)$o['discount_amount'] - $promoSum);

// Discount total
$discountTotal = min($subtotal, $promoSum + $manualPart);

// Settings (tax/service)
function get_setting(PDO $pdo, int $tenantId, string $key, string $def='0.00'): float {
  $s = $pdo->prepare("SELECT `value` FROM settings WHERE tenant_id=:t AND `key`=:k LIMIT 1");
  $s->execute([':t'=>$tenantId, ':k'=>$key]);
  $v = $s->fetchColumn();
  return (float)($v !== false ? $v : $def);
}
$taxPercent     = get_setting(db(), $tenantId, 'tax_percent', '0.00');
$servicePercent = get_setting(db(), $tenantId, 'service_percent', '0.00');

// Commission %
$commissionPercent = 0.0;
if ($aggId) {
  $sa = db()->prepare("SELECT default_commission_percent FROM aggregators WHERE id=:id AND tenant_id=:t AND is_active=1");
  $sa->execute([':id'=>$aggId, ':t'=>$tenantId]);
  $commissionPercent = (float)($sa->fetchColumn() ?: 0.0);
}

// Totals
$subAfter = $subtotal - $discountTotal;
$taxAmount     = round($subAfter * ($taxPercent/100), 3);
$serviceAmount = round($subAfter * ($servicePercent/100), 3);
$totalAmount   = round($subAfter + $taxAmount + $serviceAmount, 3);
$commissionAmount = $aggId ? round($totalAmount * ($commissionPercent/100), 3) : 0.0;

// Update order
$up = db()->prepare("
  UPDATE orders
  SET subtotal_amount=:sub,
      discount_amount=:disc,
      tax_percent=:tax_p, tax_amount=:tax_a,
      service_percent=:svc_p, service_amount=:svc_a,
      commission_percent=:comm_p, commission_amount=:comm_a,
      total_amount=:total_a,
      updated_at = NOW()
  WHERE id=:id
");
$up->execute([
  ':sub'=>$subtotal,
  ':disc'=>$discountTotal,
  ':tax_p'=>$taxPercent, ':tax_a'=>$taxAmount,
  ':svc_p'=>$servicePercent, ':svc_a'=>$serviceAmount,
  ':comm_p'=>$commissionPercent, ':comm_a'=>$commissionAmount,
  ':total_a'=>$totalAmount,
  ':id'=>$orderId
]);

header('Location: '.base_url('views/admin/orders/show.php?id='.$orderId.'&msg='.rawurlencode('Totals recalculated')));