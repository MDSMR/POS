<?php
// public_html/views/admin/order_edit.php — Edit Order (matches Add; fixed columns)
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
$tenantId=(int)($user['tenant_id']??0);

/* CSRF */
if (empty($_SESSION['csrf_orders'])) { $_SESSION['csrf_orders'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_orders'];

/* Helpers */
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function fix2($n){ return number_format((float)$n,2,'.',''); }

/* Input */
$id=(int)($_GET['id']??0);
if($id<=0){ $_SESSION['flash']='Order not specified.'; header('Location:/views/admin/orders/index.php'); exit; }

/* Load lists + order */
$branches=[]; $aggregators=[]; $order=null; $db_msg='';
if($bootstrap_ok){
  try{
    $pdo=db();

    $st=$pdo->prepare("SELECT id, name FROM branches WHERE tenant_id=:t AND (is_active=1 OR is_active IS NULL) ORDER BY name ASC");
    $st->execute([':t'=>$tenantId]); $branches=$st->fetchAll()?:[];

    try{
      $st=$pdo->prepare("SELECT id, name FROM aggregators WHERE tenant_id=:t ORDER BY name ASC");
      $st->execute([':t'=>$tenantId]); $aggregators=$st->fetchAll()?:[];
    }catch(Throwable $e){ /* optional table */ }

    $sql="
      SELECT o.*
      FROM orders o
      WHERE o.id=:id
      LIMIT 1
    ";
    $st=$pdo->prepare($sql); $st->execute([':id'=>$id]); $order=$st->fetch();
    if(!$order){ $_SESSION['flash']='Order not found.'; header('Location:/views/admin/orders/index.php'); exit; }

  }catch(Throwable $e){ $db_msg=$e->getMessage(); }
}

/* Flash */
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Edit Order · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f7f8fa; --card:#fff; --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb;
  --ok:#059669; --warn:#d97706; --off:#991b1b; --subtle:#f3f4f6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto;color:#111827}
.container{max-width:1000px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.h2{font-size:14px;font-weight:800;margin:8px 0 12px;color:#111827}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
.input,select,textarea{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px;background:#fff}
.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98)}
.btn-primary{background:var(--primary);color:#fff;border-color:#2563eb}
.btn-sm{padding:6px 12px;line-height:1.1}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.small{color:var(--muted);font-size:12px}
.totals{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}
@media (max-width:900px){.totals{grid-template-columns:1fr 1fr}}
.tot-cell{border:1px dashed var(--border);border-radius:10px;padding:10px;background:#fff}
.tot-cell strong{display:block;font-size:12px;color:var(--muted);margin-bottom:6px}
.tot-val{font-weight:800}
</style>
</head>
<body>

<?php $active='orders'; require __DIR__.'/../partials/admin_nav.php'; ?>

<div class="container">
  <?php if($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>
  <?php if($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>

  <form method="post" action="/controllers/admin/orders_update.php" id="orderForm" novalidate>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$order['id'] ?>">

    <div class="section">
      <div class="row">
        <div class="h1">Edit Order #<?= (int)$order['id'] ?></div>
        <div style="display:flex;gap:8px;align-items:center">
          <span class="small">Created: <?= h(date('Y-m-d H:i', strtotime((string)$order['created_at']))) ?></span>
          <a class="btn btn-sm" href="/views/admin/order_view.php?id=<?= (int)$order['id'] ?>">View</a>
          <a class="btn btn-sm" href="/views/admin/orders/index.php">Back</a>
          <button class="btn btn-primary btn-sm" type="submit">Update</button>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="h2">Basics</div>
      <div class="grid">
        <div>
          <label>Branch</label>
          <select class="input" name="branch_id" required>
            <?php foreach($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= ((int)$order['branch_id']===(int)$b['id']?'selected':'') ?>>
                <?= h($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Order Type</label>
          <select class="input" name="order_type" id="orderType" required>
            <option value="dine_in"  <?= ($order['order_type']==='dine_in'?'selected':'') ?>>Dine in</option>
            <option value="takeaway" <?= ($order['order_type']==='takeaway'?'selected':'') ?>>Take Away</option>
            <option value="delivery" <?= ($order['order_type']==='delivery'?'selected':'') ?>>Delivery</option>
          </select>
        </div>
      </div>

      <div class="grid" id="aggRow" style="<?= ($order['order_type']==='delivery'?'':'display:none') ?>">
        <div>
          <label>Aggregator (Delivery only)</label>
          <select class="input" name="aggregator_id">
            <option value="">— Select —</option>
            <?php foreach($aggregators as $a): ?>
              <option value="<?= (int)$a['id'] ?>" <?= ((int)($order['aggregator_id']??0)===(int)$a['id']?'selected':'') ?>>
                <?= h($a['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>External Reference</label>
          <input class="input" name="external_order_reference" maxlength="100"
                 value="<?= h($order['external_order_reference'] ?? '') ?>">
        </div>
      </div>

      <div class="grid">
        <div>
          <label>Customer Name</label>
          <input class="input" name="customer_name" maxlength="160" value="<?= h($order['customer_name'] ?? '') ?>">
        </div>
        <div>
          <label>Guest Count</label>
          <input class="input" name="guest_count" type="number" inputmode="numeric" min="0" value="<?= (int)($order['guest_count'] ?? 0) ?>">
        </div>
      </div>

      <div class="grid">
        <div>
          <label>Receipt Reference</label>
          <input class="input" name="receipt_reference" maxlength="100" value="<?= h($order['receipt_reference'] ?? '') ?>">
        </div>
        <div>
          <label>Order Notes</label>
          <input class="input" name="order_notes" maxlength="255" value="<?= h($order['order_notes'] ?? '') ?>">
        </div>
      </div>
    </div>

    <div class="section">
      <div class="h2">Amounts</div>
      <div class="grid">
        <div>
          <label>Subtotal</label>
          <input class="input" id="subtotal" name="subtotal_amount" inputmode="decimal" value="<?= h(fix2($order['subtotal_amount'] ?? 0)) ?>">
        </div>
        <div>
          <label>Discount</label>
          <input class="input" id="discount" name="discount_amount" inputmode="decimal" value="<?= h(fix2($order['discount_amount'] ?? 0)) ?>">
        </div>
      </div>
      <div class="grid">
        <div>
          <label>Tax %</label>
          <input class="input" id="taxp" name="tax_percent" inputmode="decimal" value="<?= h(fix2($order['tax_percent'] ?? 0)) ?>">
        </div>
        <div>
          <label>Service %</label>
          <input class="input" id="servp" name="service_percent" inputmode="decimal" value="<?= h(fix2($order['service_percent'] ?? 0)) ?>">
        </div>
      </div>
      <div class="grid">
        <div>
          <label>Commission (absolute)</label>
          <input class="input" id="comm" name="commission_total_amount" inputmode="decimal" value="<?= h(fix2($order['commission_total_amount'] ?? 0)) ?>">
        </div>
        <div>
          <label>Total (auto)</label>
          <input class="input" id="total" name="total_amount" inputmode="decimal" value="<?= h(fix2($order['total_amount'] ?? 0)) ?>" readonly>
        </div>
      </div>

      <div class="totals" style="margin-top:10px">
        <?php
          $sub = (float)($order['subtotal_amount'] ?? 0);
          $dis = (float)($order['discount_amount'] ?? 0);
          $taxpV= (float)($order['tax_percent'] ?? 0);
          $servpV=(float)($order['service_percent'] ?? 0);
          $tax = (float)($order['tax_amount'] ?? 0);
          $serv= (float)($order['service_amount'] ?? 0);
          $cm  = (float)($order['commission_total_amount'] ?? 0);
        ?>
        <div class="tot-cell"><strong>Subtotal</strong><span class="tot-val" id="s1"><?= h(fix2($sub)) ?></span></div>
        <div class="tot-cell"><strong>− Discount</strong><span class="tot-val" id="s2"><?= h(fix2($dis)) ?></span></div>
        <div class="tot-cell"><strong>+ Tax</strong><span class="tot-val" id="s3"><?= h(fix2($tax)) ?></span></div>
        <div class="tot-cell"><strong>+ Service</strong><span class="tot-val" id="s4"><?= h(fix2($serv)) ?></span></div>
        <div class="tot-cell"><strong>+ Commission</strong><span class="tot-val" id="s5"><?= h(fix2($cm)) ?></span></div>
      </div>
    </div>

    <div class="section">
      <div class="h2">Status & Payment</div>
      <div class="grid">
        <div>
          <label>Order Status</label>
          <select class="input" name="status">
            <?php $st = $order['status']??'open'; ?>
            <option value="open"      <?= ($st==='open'?'selected':'') ?>>Open</option>
            <option value="held"      <?= ($st==='held'?'selected':'') ?>>Held</option>
            <option value="sent"      <?= ($st==='sent'?'selected':'') ?>>Sent</option>
            <option value="preparing" <?= ($st==='preparing'?'selected':'') ?>>Preparing</option>
            <option value="ready"     <?= ($st==='ready'?'selected':'') ?>>Ready</option>
            <option value="served"    <?= ($st==='served'?'selected':'') ?>>Served</option>
            <option value="closed"    <?= ($st==='closed'?'selected':'') ?>>Closed</option>
            <option value="voided"    <?= ($st==='voided'?'selected':'') ?>>Voided</option>
            <option value="cancelled" <?= ($st==='cancelled'?'selected':'') ?>>Cancelled</option>
            <option value="refunded"  <?= ($st==='refunded'?'selected':'') ?>>Refunded</option>
          </select>
        </div>
        <div>
          <label>Payment Status</label>
          <select class="input" name="payment_status">
            <?php $ps = $order['payment_status']??'unpaid'; ?>
            <option value="unpaid" <?= ($ps==='unpaid'?'selected':'') ?>>Unpaid</option>
            <option value="paid"   <?= ($ps==='paid'?'selected':'') ?>>Paid</option>
            <option value="voided" <?= ($ps==='voided'?'selected':'') ?>>Voided</option>
          </select>
        </div>
      </div>
      <div class="grid">
        <div>
          <label>Payment Method</label>
          <select class="input" name="payment_method">
            <?php $pm=$order['payment_method']??''; ?>
            <option value=""      <?= ($pm===''?'selected':'') ?>>—</option>
            <option value="cash"  <?= ($pm==='cash'?'selected':'') ?>>Cash</option>
            <option value="card"  <?= ($pm==='card'?'selected':'') ?>>Card</option>
            <option value="wallet"<?= ($pm==='wallet'?'selected':'') ?>>Wallet</option>
          </select>
        </div>
        <div>
          <label>POS Session (optional)</label>
          <input class="input" name="session_id" inputmode="numeric" value="<?= h($order['session_id'] ?? '') ?>">
        </div>
      </div>
      <input type="hidden" name="source_channel" value="<?= h($order['source_channel'] ?? 'pos') ?>">
    </div>

    <div class="section" style="display:flex;justify-content:flex-end;gap:10px">
      <a class="btn" href="/views/admin/orders/index.php">Cancel</a>
      <button class="btn btn-primary" type="submit">Update</button>
    </div>
  </form>
</div>

<script>
/* Show aggregator row only for delivery */
const orderType=document.getElementById('orderType');
const aggRow=document.getElementById('aggRow');
function applyType(){ aggRow.style.display = orderType.value==='delivery' ? '' : 'none'; }
orderType.addEventListener('change', applyType); applyType();

/* Money helpers */
function num(v){ const n=parseFloat(String(v).replace(/[^0-9.\-]/g,'')); return isFinite(n)?n:0; }
function fix2(n){ return (Math.round(n*100)/100).toFixed(2); }

const subtotal=document.getElementById('subtotal');
const discount=document.getElementById('discount');
const taxp=document.getElementById('taxp');
const servp=document.getElementById('servp');
const comm=document.getElementById('comm');
const total=document.getElementById('total');

const s1=document.getElementById('s1');
const s2=document.getElementById('s2');
const s3=document.getElementById('s3');
const s4=document.getElementById('s4');
const s5=document.getElementById('s5');

function recalc(){
  const sub = num(subtotal.value);
  const dis = num(discount.value);
  const tax = (num(taxp.value)/100)*Math.max(sub - dis, 0);
  const srv = (num(servp.value)/100)*Math.max(sub - dis, 0);
  const cm  = num(comm.value);
  const tot = Math.max(sub - dis, 0) + tax + srv + cm;

  s1.textContent = fix2(sub);
  s2.textContent = fix2(dis);
  s3.textContent = fix2(tax);
  s4.textContent = fix2(srv);
  s5.textContent = fix2(cm);
  total.value    = fix2(tot);
}
[subtotal,discount,taxp,servp,comm].forEach(el=>el.addEventListener('input', recalc));
recalc();

/* Mild validation on submit */
document.getElementById('orderForm').addEventListener('submit', function(e){
  const b=this.querySelector('[name="branch_id"]');
  const t=this.querySelector('[name="order_type"]');
  if(!b.value){ e.preventDefault(); alert('Please pick a Branch.'); b.focus(); return; }
  if(!t.value){ e.preventDefault(); alert('Please pick Order Type.'); t.focus(); return; }
});
</script>
</body>
</html>