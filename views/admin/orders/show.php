<?php
// public_html/views/admin/orders/show.php
declare(strict_types=1);

// --- Minimal admin guard (works with your existing backend session) ---
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
function admin_user() { return $_SESSION['user'] ?? null; }
function admin_require() {
  $u = admin_user();
  $okRoles = ['admin','manager','pos_manager']; // allow admin/manager/POS manager to view
  if (!$u || empty($u['role_key']) || !in_array($u['role_key'], $okRoles, true)) {
    http_response_code(403);
    echo "Forbidden"; exit;
  }
}
if (!function_exists('base_url')) {
  function base_url(string $path=''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return rtrim($scheme.'://'.$host, '/').'/'.ltrim($path,'/');
  }
}
admin_require();

// --- DB ---
require_once __DIR__ . '/../../config/db.php';
if (!function_exists('db')) { function db(): PDO { global $pdo; return $pdo; } }

// --- Inputs ---
$orderId = (int)($_GET['id'] ?? 0);
if ($orderId <= 0) { http_response_code(422); echo "Missing id"; exit; }

$msg = $_GET['msg'] ?? '';
$err = $_GET['err'] ?? '';

// --- Fetch order + related ---
$sqlOrder = "
  SELECT o.*, b.name AS branch_name, a.name AS aggregator_name
  FROM orders o
  LEFT JOIN branches b ON b.id = o.branch_id
  LEFT JOIN aggregators a ON a.id = o.aggregator_id
  WHERE o.id = :id
  LIMIT 1
";
$st = db()->prepare($sqlOrder);
$st->execute([':id'=>$orderId]);
$order = $st->fetch(PDO::FETCH_ASSOC);
if (!$order) { http_response_code(404); echo "Order not found"; exit; }

// Items
$sqlItems = "
  SELECT oi.*
  FROM order_items oi
  WHERE oi.order_id = :id
  ORDER BY oi.id
";
$sti = db()->prepare($sqlItems);
$sti->execute([':id'=>$orderId]);
$items = $sti->fetchAll(PDO::FETCH_ASSOC);

// Variations, grouped by order_item_id
$stiv = db()->prepare("
  SELECT oiv.*
  FROM order_item_variations oiv
  JOIN order_items oi ON oi.id = oiv.order_item_id
  WHERE oi.order_id = :id
  ORDER BY oiv.id
");
$stiv->execute([':id'=>$orderId]);
$varsByItem = [];
foreach ($stiv->fetchAll(PDO::FETCH_ASSOC) as $v) {
  $varsByItem[(int)$v['order_item_id']][] = $v;
}

// Discounts (promo rows)
$stD = db()->prepare("SELECT * FROM order_discounts WHERE order_id = :id ORDER BY id");
$stD->execute([':id'=>$orderId]);
$discountRows = $stD->fetchAll(PDO::FETCH_ASSOC);
$promoTotal = 0.0;
foreach ($discountRows as $dr) { $promoTotal += (float)$dr['amount_applied']; }

// Manual discount (tracked in orders.discount_amount minus promo rows)
$discountAmount = (float)$order['discount_amount'];
$manualPart = max(0.0, round($discountAmount - $promoTotal, 3));

// Tenant settings (display note)
$taxPercent     = (float)($order['tax_percent'] ?? 0);
$servicePercent = (float)($order['service_percent'] ?? 0);
$commissionPercent = (float)($order['commission_percent'] ?? 0);

// Helpers
function kd($n){ return 'KD '.number_format((float)$n, 3, '.', ''); }

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Order #<?= (int)$orderId ?> · Admin · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--border:#e5e7eb;--muted:#6b7280;--ink:#0f172a;--bg:#f8fafc;--card:#fff;--brand:#111827;--ok:#065f46;--okbg:#eafff4;--warn:#92400e;--warnbg:#fef3c7}
body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Roboto}
.wrap{max-width:1100px;margin:24px auto;padding:0 12px}
a{text-decoration:none;color:#2563eb}
.h1{font-size:22px;margin:0 0 12px;font-weight:800}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.btn{padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--brand);color:#fff;cursor:pointer}
.btn.secondary{background:#fff;color:#111;border-color:var(--border)}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;margin-bottom:12px}
.card-head{display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid var(--border)}
.card-body{padding:12px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.kv{display:grid;grid-template-columns:160px 1fr;gap:6px 12px}
.kv div{padding:4px 0;border-bottom:1px dashed var(--border)}
.table{width:100%;border-collapse:separate;border-spacing:0 6px}
.table th{font-size:12px;text-align:left;color:var(--muted);padding:4px 6px}
.table td{background:#fff;border:1px solid var(--border);border-left-width:0;border-right-width:0;padding:8px 6px;vertical-align:top}
.table tr td:first-child{border-left-width:1px;border-top-left-radius:10px;border-bottom-left-radius:10px}
.table tr td:last-child{border-right-width:1px;border-top-right-radius:10px;border-bottom-right-radius:10px}
.badge{display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:12px;background:#fff}
.note{font-size:12px;color:var(--muted)}
.ok{color:var(--ok);background:var(--okbg);border:1px solid #bcf7d0;border-radius:8px;padding:8px}
.warn{color:var(--warn);background:var(--warnbg);border:1px solid #fde68a;border-radius:8px;padding:8px}
.right{display:flex;gap:8px;align-items:center}
</style>
</head>
<body>
  <div class="wrap">
    <div class="row" style="justify-content:space-between;margin-bottom:8px">
      <div class="h1">Order #<?= (int)$orderId ?></div>
      <div class="row">
        <a class="btn secondary" href="<?= htmlspecialchars(base_url('views/admin/orders/index.php')) ?>">← Back to Orders</a>
      </div>
    </div>

    <?php if ($msg): ?><div class="ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="warn"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-head"><strong>Header</strong></div>
      <div class="card-body grid2">
        <div class="kv">
          <div><b>Status</b></div><div><?= htmlspecialchars($order['status']) ?></div>
          <div><b>Type</b></div><div><?= htmlspecialchars($order['order_type']) ?></div>
          <div><b>Branch</b></div><div><?= htmlspecialchars($order['branch_name'] ?? ('#'.$order['branch_id'])) ?></div>
          <div><b>Aggregator</b></div><div><?= $order['aggregator_id'] ? htmlspecialchars($order['aggregator_name'] ?? ('#'.$order['aggregator_id'])) : '—' ?></div>
          <div><b>Guest Count</b></div><div><?= $order['guest_count'] ? (int)$order['guest_count'] : '—' ?></div>
          <div><b>Table</b></div><div><?= $order['table_id'] ? '#'.(int)$order['table_id'] : '—' ?></div>
        </div>
        <div class="kv">
          <div><b>Subtotal</b></div><div><?= kd($order['subtotal_amount']) ?></div>
          <div><b>Discount</b></div><div><?= kd($order['discount_amount']) ?> <span class="note">(Promo <?= kd($promoTotal) ?> + Manual <?= kd($manualPart) ?>)</span></div>
          <div><b>Tax</b></div><div><?= kd($order['tax_amount']) ?> <span class="note">(<?= number_format($taxPercent,2) ?>%)</span></div>
          <div><b>Service</b></div><div><?= kd($order['service_amount']) ?> <span class="note">(<?= number_format($servicePercent,2) ?>%)</span></div>
          <div><b>Aggregator Comm.</b></div><div><?= kd($order['commission_amount']) ?> <span class="note">(<?= number_format($commissionPercent,2) ?>%)</span></div>
          <div><b>Total</b></div><div><b><?= kd($order['total_amount']) ?></b></div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><strong>Items</strong></div>
      <div class="card-body">
        <table class="table">
          <thead>
            <tr>
              <th>Product</th>
              <th style="width:90px">Qty</th>
              <th style="width:120px">Unit</th>
              <th style="width:130px">Line</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td>
                  <div><b><?= htmlspecialchars($it['product_name']) ?></b></div>
                  <?php $vs = $varsByItem[(int)$it['id']] ?? []; ?>
                  <?php if ($vs): ?>
                    <div class="note">
                      <?php
                        $parts = [];
                        foreach ($vs as $v) {
                          $d = (float)$v['price_delta'];
                          $parts[] = htmlspecialchars($v['variation_group']).': '.htmlspecialchars($v['variation_value']).($d ? ' (+'.number_format($d,3).')':'');
                        }
                        echo implode(' · ', $parts);
                      ?>
                    </div>
                  <?php else: ?>
                    <div class="note">No variations</div>
                  <?php endif; ?>
                </td>
                <td><?= (int)$it['quantity'] ?></td>
                <td><?= kd($it['unit_price']) ?></td>
                <td><?= kd($it['line_subtotal']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><strong>Discounts</strong></div>
      <div class="card-body">
        <?php if (!$discountRows && !$manualPart): ?>
          <div class="note">No discounts applied.</div>
        <?php else: ?>
          <?php if ($discountRows): ?>
            <div style="margin-bottom:6px"><b>Promo Discounts</b></div>
            <table class="table" style="margin-bottom:12px">
              <thead><tr><th>Rule</th><th>Promo</th><th style="width:140px">Amount</th></tr></thead>
              <tbody>
                <?php foreach ($discountRows as $dr): ?>
                  <tr>
                    <td>#<?= (int)$dr['discount_rule_id'] ?></td>
                    <td>#<?= (int)$dr['promo_code_id'] ?></td>
                    <td><?= kd($dr['amount_applied']) ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
          <?php if ($manualPart > 0): ?>
            <div><b>Manual Discount:</b> <?= kd($manualPart) ?> <span class="note">(included within order’s discount_amount)</span></div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <strong>Maintenance</strong>
        <div class="right">
          <form method="post" action="<?= htmlspecialchars(base_url('controllers/admin/orders/recalculate.php')) ?>" onsubmit="return confirm('Recalculate totals now?')">
            <input type="hidden" name="id" value="<?= (int)$orderId ?>">
            <button class="btn" type="submit">Recalculate Totals</button>
          </form>
        </div>
      </div>
      <div class="card-body">
        <div class="note">Recalculate uses the current <b>order_items</b> and <b>order_discounts</b> in DB, plus tenant settings (tax/service) and the aggregator’s default commission, to rebuild subtotal, tax, service, commission and total.</div>
      </div>
    </div>

  </div>
</body>
</html>