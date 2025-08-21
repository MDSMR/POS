<?php
// public_html/views/admin/order_view.php — Read-only view matching Add/Edit layout
declare(strict_types=1);

/* Bootstrap + session */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path=__DIR__.'/../../config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{
  $prev=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try{
    require_once $bootstrap_path;
    if(!function_exists('db')||!function_exists('use_backend_session')){
      $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok=true; }
  }catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally{ if($prev){ set_error_handler($prev); } }
}
if($bootstrap_ok){ try{ use_backend_session(); }catch(Throwable $e){ $bootstrap_warning=$bootstrap_warning?:('Session bootstrap error: '.$e->getMessage()); } }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }

/* Helpers */
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function fix2($n){ return number_format((float)$n,2,'.',''); }
function dt($v){ return $v ? date('Y-m-d H:i', strtotime((string)$v)) : '—'; }

/* Input */
$id=(int)($_GET['id']??0);
if($id<=0){ $_SESSION['flash']='Order not specified.'; header('Location:/views/admin/orders/index.php'); exit; }

/* Load */
$order=null; $db_msg='';
if($bootstrap_ok){
  try{
    $pdo=db();
    $sql="
      SELECT
        o.*,
        b.name AS branch_name,
        a.name AS aggregator_name
      FROM orders o
      LEFT JOIN branches b ON b.id=o.branch_id
      LEFT JOIN aggregators a ON a.id=o.aggregator_id
      WHERE o.id=:id
      LIMIT 1
    ";
    $st=$pdo->prepare($sql);
    $st->execute([':id'=>$id]);
    $order=$st->fetch();
    if(!$order){ $_SESSION['flash']='Order not found.'; header('Location:/views/admin/orders/index.php'); exit; }
  }catch(Throwable $e){ $db_msg=$e->getMessage(); }
}

/* Derived amounts for the mini-cards */
$sub=(float)($order['subtotal_amount']??0);
$dis=(float)($order['discount_amount']??0);
$taxp=(float)($order['tax_percent']??0);
$servp=(float)($order['service_percent']??0);
$tax=(float)($order['tax_amount']??0);
$serv=(float)($order['service_amount']??0);
$comm=(float)($order['commission_total_amount']??0);
$tot=(float)($order['total_amount']??0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>View Order · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f7f8fa; --card:#fff; --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb;
  --subtle:#f3f4f6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto;color:var(--text)}
.container{max-width:1000px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.h2{font-size:14px;font-weight:800;margin:8px 0 12px;color:#111827}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
.value{border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:#fff;min-height:42px;display:flex;align-items:center}
.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98)}
.btn-primary{background:var(--primary);color:#fff;border-color:#2563eb}
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:1px 6px;font-size:11px;background:#f3f4f6;line-height:1.3}
.small{color:var(--muted);font-size:12px}
.totals{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
@media (max-width:900px){.totals{grid-template-columns:1fr 1fr}}
.tot-cell{border:1px dashed var(--border);border-radius:10px;padding:10px;background:#fff}
.tot-cell strong{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
.tot-val{font-weight:800}
.kv{display:grid;grid-template-columns:180px 1fr;gap:8px;margin:6px 0}
.kv .k{color:var(--muted)}
.kv .v{font-weight:600}
</style>
</head>
<body>

<?php $active='orders'; require __DIR__.'/../partials/admin_nav.php'; ?>

<div class="container">
  <?php if($bootstrap_warning): ?><div class="section small"><?= h($bootstrap_warning) ?></div><?php endif; ?>
  <?php if($db_msg): ?><div class="section small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>

  <div class="section">
    <div class="row">
      <div class="h1">Order #<?= (int)$order['id'] ?></div>
      <div style="display:flex;gap:8px;align-items:center">
        <span class="badge">Channel: <?= h($order['source_channel'] ?? 'pos') ?></span>
        <a class="btn" href="/views/admin/orders/index.php">Back</a>
        <a class="btn btn-primary" href="/views/admin/order_edit.php?id=<?= (int)$order['id'] ?>">Edit</a>
      </div>
    </div>
    <div class="kv"><div class="k">Created</div><div class="v"><?= h(dt($order['created_at'])) ?></div></div>
    <div class="kv"><div class="k">Updated</div><div class="v"><?= h(dt($order['updated_at'])) ?></div></div>
    <div class="kv"><div class="k">Closed</div><div class="v"><?= h(dt($order['closed_at'])) ?></div></div>
  </div>

  <div class="section">
    <div class="h2">Basics</div>
    <div class="grid">
      <div><label>Branch</label><div class="value"><?= h($order['branch_name'] ?? '—') ?></div></div>
      <div><label>Order Type</label><div class="value">
        <?php
          $map=['dine_in'=>'Dine in','takeaway'=>'Take Away','delivery'=>'Delivery'];
          echo h($map[$order['order_type'] ?? 'dine_in'] ?? 'Dine in');
        ?>
      </div></div>
    </div>
    <div class="grid" style="<?= ($order['order_type']==='delivery'?'':'display:none') ?>">
      <div><label>Aggregator</label><div class="value"><?= h($order['aggregator_name'] ?? '—') ?></div></div>
      <div><label>External Reference</label><div class="value"><?= h($order['external_order_reference'] ?? '') ?></div></div>
    </div>
    <div class="grid">
      <div><label>Customer Name</label><div class="value"><?= h($order['customer_name'] ?? '') ?></div></div>
      <div><label>Guest Count</label><div class="value"><?= (int)($order['guest_count'] ?? 0) ?></div></div>
    </div>
    <div class="grid">
      <div><label>Receipt Reference</label><div class="value"><?= h($order['receipt_reference'] ?? '') ?></div></div>
      <div><label>Order Notes</label><div class="value"><?= h($order['order_notes'] ?? '') ?></div></div>
    </div>
  </div>

  <div class="section">
    <div class="h2">Amounts</div>
    <div class="totals">
      <div class="tot-cell"><strong>Subtotal</strong><span class="tot-val"><?= h(fix2($sub)) ?></span></div>
      <div class="tot-cell"><strong>− Discount</strong><span class="tot-val"><?= h(fix2($dis)) ?></span></div>
      <div class="tot-cell"><strong>+ Tax (<?= h(fix2($taxp)) ?>%)</strong><span class="tot-val"><?= h(fix2($tax)) ?></span></div>
      <div class="tot-cell"><strong>+ Service (<?= h(fix2($servp)) ?>%)</strong><span class="tot-val"><?= h(fix2($serv)) ?></span></div>
      <div class="tot-cell"><strong>+ Commission</strong><span class="tot-val"><?= h(fix2($comm)) ?></span></div>
    </div>
    <div class="kv" style="margin-top:10px">
      <div class="k">Total</div><div class="v"><?= h(fix2($tot)) ?></div>
    </div>
  </div>

  <div class="section">
    <div class="h2">Status & Payment</div>
    <div class="grid">
      <div><label>Order Status</label><div class="value"><?= h($order['status'] ?? 'open') ?></div></div>
      <div><label>Payment Status</label><div class="value"><?= h($order['payment_status'] ?? 'unpaid') ?></div></div>
    </div>
    <div class="grid">
      <div><label>Payment Method</label><div class="value"><?= h($order['payment_method'] ?? '—') ?></div></div>
      <div><label>POS Session</label><div class="value"><?= h($order['session_id'] ?? '') ?></div></div>
    </div>
    <div class="small" style="margin-top:6px">Source Channel: <strong><?= h($order['source_channel'] ?? 'pos') ?></strong></div>
  </div>

  <div class="section" style="display:flex;justify-content:flex-end;gap:10px">
    <a class="btn" href="/views/admin/orders/index.php">Back</a>
    <a class="btn btn-primary" href="/views/admin/order_edit.php?id=<?= (int)$order['id'] ?>">Edit</a>
  </div>
</div>
</body>
</html>