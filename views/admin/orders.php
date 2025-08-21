<?php
// views/admin/orders.php — Orders list & filters (single-line rows, no overlap)
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

/* Helpers */
function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function donly(?string $dt): string{ if(!$dt) return '-'; $t=strtotime($dt); if($t<=0) return '-'; return date('d-m-Y',$t); }
function typeLabel(string $t): string{
  return $t==='dine_in'?'Dine in':($t==='takeaway'?'Take Away':($t==='delivery'?'Delivery':ucfirst(str_replace('_',' ',$t))));
}
function payLabel(?string $p): string{ return $p==='paid'?'Paid':($p==='voided'?'Voided':'Unpaid'); }

/* Filters */
$q=trim((string)($_GET['q']??'')); $branch=(int)($_GET['branch']??0);
$type=(string)($_GET['type']??'all'); $payment=(string)($_GET['payment']??'all');
$date_preset=(string)($_GET['d']??'day'); $from=(string)($_GET['from']??''); $to=(string)($_GET['to']??'');

/* Branches */
$branches=[]; $db_msg='';
if($bootstrap_ok){
  try{
    $pdo=db();
    $st=$pdo->prepare("SELECT id,name FROM branches WHERE tenant_id=:t ORDER BY name ASC");
    $st->execute([':t'=>$tenantId]);
    $branches=$st->fetchAll()?:[];
  }catch(Throwable $e){ $db_msg=$e->getMessage(); }
}

/* Date window */
$start=null; $end=null; $today=new DateTimeImmutable('today');
switch($date_preset){
  case 'week':
    $ws=$today->modify('monday this week'); $we=$ws->modify('+6 days');
    $start=$ws->format('Y-m-d 00:00:00'); $end=$we->format('Y-m-d 23:59:59'); break;
  case 'month':
    $ms=$today->modify('first day of this month'); $me=$today->modify('last day of this month');
    $start=$ms->format('Y-m-d 00:00:00'); $end=$me->format('Y-m-d 23:59:59'); break;
  case 'year':
    $ys=new DateTimeImmutable(date('Y').'-01-01'); $ye=new DateTimeImmutable(date('Y').'-12-31');
    $start=$ys->format('Y-m-d 00:00:00'); $end=$ye->format('Y-m-d 23:59:59'); break;
  case 'custom':
    $fd=$from!==''?DateTimeImmutable::createFromFormat('Y-m-d',$from):$today;
    $td=$to  !==''?DateTimeImmutable::createFromFormat('Y-m-d',$to)  :$today;
    if(!$fd) $fd=$today; if(!$td) $td=$today; if($fd>$td){ [$fd,$td]=[$td,$fd]; }
    $start=$fd->format('Y-m-d 00:00:00'); $end=$td->format('Y-m-d 23:59:59'); break;
  case 'day':
  default:
    $start=$today->format('Y-m-d 00:00:00'); $end=$today->format('Y-m-d 23:59:59'); $date_preset='day';
}

/* Load orders */
$rows=[];
if($bootstrap_ok){
  try{
    $pdo=db();
    $where=["o.tenant_id=:t"]; $args=[':t'=>$tenantId];
    if($q!==''){
      $where[]="(o.id=:idq OR o.customer_name LIKE :q OR o.receipt_reference LIKE :q OR o.external_order_reference LIKE :q)";
      $args[':idq']=ctype_digit($q)?(int)$q:-1; $args[':q']='%'.$q.'%';
    }
    if($branch>0){ $where[]="o.branch_id=:b"; $args[':b']=$branch; }
    if(in_array($type,['dine_in','takeaway','delivery'],true)){ $where[]="o.order_type=:ot"; $args[':ot']=$type; }
    if(in_array($payment,['paid','unpaid','voided'],true)){ $where[]="o.payment_status=:ps"; $args[':ps']=$payment; }
    if($start && $end){ $where[]="(o.created_at BETWEEN :ds AND :de)"; $args[':ds']=$start; $args[':de']=$end; }
    $whereSql='WHERE '.implode(' AND ',$where);

    $sql="
      SELECT o.id,o.created_at,o.order_type,o.status,o.payment_status,o.customer_name,o.total_amount,
             b.name AS branch_name
      FROM orders o
      LEFT JOIN branches b ON b.id=o.branch_id
      $whereSql
      ORDER BY o.id DESC
      LIMIT 500
    ";
    $st=$pdo->prepare($sql); $st->execute($args);
    $rows=$st->fetchAll()?:[];
  }catch(Throwable $e){ $db_msg=$e->getMessage(); }
}

/* Flash */
$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Orders · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f7f8fa; --card:#fff; --text:#111827; --muted:#6b7280; --primary:#2563eb;
  --border:#e5e7eb; --ok:#059669; --warn:#d97706; --off:#991b1b;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto;color:var(--text)}
.container{max-width:1400px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}

/* Buttons */
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98)}
.btn-primary{background:var(--primary);color:#fff;border-color:#2563eb}
.btn-add{padding:6px 12px; position:relative; top:-2px;}
.btn-sm{padding:4px 8px; line-height:1.1; font-size:12px}

/* Inputs */
.input,.select{border:1px solid var(--border);border-radius:10px;padding:8px 10px;background:#fff}
.input::placeholder{color:#9CA3AF}
.small{color:#6b7280;font-size:12px}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}

/* Filters */
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.filters-top{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.filters-bottom{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:8px}
.date-input{width:140px}
.hidden{display:none}

/* Table wrapper allows horizontal scroll if needed */
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:12px}

/* Table */
.table{
  width:100%;
  border-collapse:separate;border-spacing:0;
  /* auto layout prevents squeeze/overlap at the end; min-width guarantees room */
  table-layout:auto;
  font-size:12.5px;
  min-width: 1120px; /* keep content on one line, scroll if needed */
}
.table th,.table td{
  padding:8px 6px;
  border-bottom:1px solid var(--border);
  text-align:left;vertical-align:middle;
  white-space:nowrap;
}
.table th{font-size:12px;color:var(--muted);font-weight:700}

/* Column sizing (auto respects min/max) */
.col-id{min-width:64px; width:72px}
.col-created{min-width:100px; width:110px}
.col-branch{min-width:150px; width:170px}
.col-type{min-width:96px; width:110px}
.col-status{min-width:110px; width:120px}
.col-payment{min-width:110px; width:120px}
.col-customer{min-width:180px}
.col-total{min-width:90px; width:100px; text-align:right}
.col-actions{min-width:170px; width:180px; text-align:right}

/* Text helpers */
.ellipsis{max-width:100%; overflow:hidden; text-overflow:ellipsis}

/* Badges */
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:1px 6px;font-size:11px;background:#f3f4f6;line-height:1.3}
.badge.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.badge.warn{background:#fff7ed;border-color:#ffedd5;color:#7c2d12}
.badge.off{background:#fee2e2;border-color:#fecaca;color:#7f1d1d}

/* Actions */
.actions{display:inline-flex;gap:6px;flex-wrap:nowrap}
.actions .btn-sm{padding:4px 8px}
</style>
</head>
<body>

<?php $active='orders'; require __DIR__.'/../partials/admin_nav.php'; ?>

<div class="container">
  <?php if($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>
  <?php if($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>

  <div class="section">
    <div class="row" style="margin-bottom:8px">
      <div class="h1">Orders</div>
      <div><a class="btn btn-primary btn-add" href="/views/admin/order_add.php">Add</a></div>
    </div>

    <form id="filterForm" method="get" action="" class="toolbar">
      <!-- Top row -->
      <div class="filters-top" style="width:100%">
        <input class="input" type="text" name="q" placeholder="Search" value="<?= h($q) ?>" id="q" autocomplete="off">
        <select class="select" name="branch" id="branch">
          <option value="0" <?= $branch===0?'selected':'' ?>>All branches</option>
          <?php foreach($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $branch===(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" name="type" id="type">
          <option value="all" <?= $type==='all'?'selected':'' ?>>All types</option>
          <option value="dine_in" <?= $type==='dine_in'?'selected':'' ?>>Dine in</option>
          <option value="takeaway" <?= $type==='takeaway'?'selected':'' ?>>Take Away</option>
          <option value="delivery" <?= $type==='delivery'?'selected':'' ?>>Delivery</option>
        </select>
        <select class="select" name="payment" id="payment">
          <option value="all" <?= $payment==='all'?'selected':'' ?>>All payments</option>
          <option value="paid" <?= $payment==='paid'?'selected':'' ?>>Paid</option>
          <option value="unpaid" <?= $payment==='unpaid'?'selected':'' ?>>Unpaid</option>
          <option value="voided" <?= $payment==='voided'?'selected':'' ?>>Voided</option>
        </select>
        <select class="select" name="d" id="d">
          <option value="day"   <?= $date_preset==='day'?'selected':'' ?>>Today</option>
          <option value="week"  <?= $date_preset==='week'?'selected':'' ?>>This Week</option>
          <option value="month" <?= $date_preset==='month'?'selected':'' ?>>Current Month</option>
          <option value="year"  <?= $date_preset==='year'?'selected':'' ?>>Current Year</option>
          <option value="custom"<?= $date_preset==='custom'?'selected':'' ?>>Custom</option>
        </select>
      </div>

      <!-- Bottom row (only for custom) -->
      <div class="filters-bottom <?= $date_preset==='custom'?'':'hidden' ?>" id="customRow">
        <?php $from_val=$from!==''?$from:date('Y-m-d'); $to_val=$to!==''?$to:date('Y-m-d'); ?>
        <input class="input date-input" type="date" name="from" value="<?= h($from_val) ?>" id="from">
        <span class="small">to</span>
        <input class="input date-input" type="date" name="to" value="<?= h($to_val) ?>" id="to">
        <button class="btn btn-primary btn-sm" type="submit" id="applyBtn">Filter</button>
      </div>
    </form>

    <div class="table-wrap" style="margin-top:12px">
      <table class="table">
        <thead>
          <tr>
            <th class="col-id">ID</th>
            <th class="col-created">Created</th>
            <th class="col-branch">Branch</th>
            <th class="col-type">Type</th>
            <th class="col-status">Status</th>
            <th class="col-payment">Payment</th>
            <th class="col-customer">Customer</th>
            <th class="col-total">Total</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="9" class="small" style="color:#6b7280">No orders found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td class="col-id">#<?= (int)$r['id'] ?></td>
            <td class="col-created"><?= h(donly($r['created_at'])) ?></td>
            <td class="col-branch ellipsis" title="<?= h($r['branch_name'] ?: '-') ?>"><?= h($r['branch_name'] ?: '-') ?></td>
            <td class="col-type"><?= h(typeLabel((string)$r['order_type'])) ?></td>
            <td class="col-status">
              <?php
                $st=(string)($r['status']??'open');
                $cls=$st==='closed'?'ok':($st==='cancelled'?'off':'warn');
                echo '<span class="badge '.$cls.'">'.h(ucfirst($st?:'open')).'</span>';
              ?>
            </td>
            <td class="col-payment">
              <?php
                $ps=(string)($r['payment_status']??'unpaid');
                $cls=$ps==='paid'?'ok':($ps==='voided'?'off':'warn');
                echo '<span class="badge '.$cls.'">'.h(payLabel($ps)).'</span>';
              ?>
            </td>
            <td class="col-customer ellipsis" title="<?= h($r['customer_name'] ?: '-') ?>"><?= h($r['customer_name'] ?: '-') ?></td>
            <td class="col-total"><strong><?= number_format((float)$r['total_amount'],2) ?></strong></td>
            <td class="col-actions">
              <div class="actions">
                <a class="btn btn-sm" href="/views/admin/order_view.php?id=<?= (int)$r['id'] ?>">View</a>
                <a class="btn btn-sm" href="/views/admin/order_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
/* Auto-apply (except Custom) */
const form=document.getElementById('filterForm');
const q=document.getElementById('q');
const branch=document.getElementById('branch');
const type=document.getElementById('type');
const payment=document.getElementById('payment');
const dsel=document.getElementById('d');
const customRow=document.getElementById('customRow');

let t=null;
function autosubmit(){ if(dsel.value==='custom') return; form.submit(); }
q.addEventListener('input', ()=>{ if(t)clearTimeout(t); t=setTimeout(autosubmit,350); });
[branch,type,payment].forEach(el=>el.addEventListener('change', autosubmit));
dsel.addEventListener('change', ()=>{
  if(dsel.value==='custom'){ customRow.classList.remove('hidden'); }
  else { customRow.classList.add('hidden'); autosubmit(); }
});
if(dsel.value==='custom'){ customRow.classList.remove('hidden'); } else { customRow.classList.add('hidden'); }
</script>
</body>
</html>