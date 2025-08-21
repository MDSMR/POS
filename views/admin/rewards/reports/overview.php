<?php
// public_html/views/admin/rewards/reports/overview.php — Rewards Reports
// - Blocks-first layout, filter hidden until a report is opened
// - Toggle behavior on tiles: open one; clicking the same tile closes it; opening another closes previous
// - Table headers forced to single line (no wrapping), with horizontal scroll on small screens
// - Defensive SQL joins for Customer Name / Mobile / Bill amount
// - CSV export per report

declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* ---------- Bootstrap /config/db.php (robust) ---------- */
$bootstrap_warning = ''; $bootstrap_ok = false; $bootstrap_tried=[]; $bootstrap_found='';
$candA = __DIR__ . '/../../../../config/db.php'; $bootstrap_tried[] = $candA;
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot !== '') { $candB = $docRoot . '/config/db.php'; if (!in_array($candB,$bootstrap_tried,true)) $bootstrap_tried[]=$candB; }
$cursor = __DIR__;
for ($i=0;$i<6;$i++){ $cursor = dirname($cursor); if ($cursor==='/'||$cursor==='.'||$cursor==='') break; $maybe = $cursor.'/config/db.php'; if (!in_array($maybe,$bootstrap_tried,true)) $bootstrap_tried[]=$maybe; }
foreach ($bootstrap_tried as $p){ if (is_file($p)){ $bootstrap_found=$p; break; } }
if ($bootstrap_found===''){
  $bootstrap_warning='Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try{
    require_once $bootstrap_found; // db(), use_backend_session()
    if (!function_exists('db') || !function_exists('use_backend_session')){
      $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok=true; }
  }catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally{ if($prevHandler) set_error_handler($prevHandler); }
}
if ($bootstrap_ok){
  try{ use_backend_session(); }catch(Throwable $e){ $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: '.$e->getMessage()); }
}

/* ---------- Auth ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* ---------- DB ---------- */
$db = function_exists('db') ? db() : null;

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function t_exists(PDO $db, string $name): bool {
  try{
    $st=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
    $st->execute([':t'=>$name]); return (int)$st->fetchColumn() > 0;
  }catch(Throwable $e){ return false; }
}
function c_exists(PDO $db, string $table, string $col): bool {
  try{
    $st=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c");
    $st->execute([':t'=>$table, ':c'=>$col]); return (int)$st->fetchColumn() > 0;
  }catch(Throwable $e){ return false; }
}
function nfmt($n,int $dec=0){ return number_format((float)$n,$dec,'.',','); }

/* Detect tenant from Settings (dynamic) with session fallback */
function detect_tenant_id(?PDO $db, int $sessionTenantId): int {
  if (!($db instanceof PDO)) return max(0,$sessionTenantId);
  $candidates = ['settings','app_settings','system_settings'];
  $keys = ['tenant_id','current_tenant_id','default_tenant_id'];
  foreach ($candidates as $tbl) {
    if (!t_exists($db,$tbl)) continue;
    $has_key  = c_exists($db,$tbl,'key');
    $has_name = c_exists($db,$tbl,'name');
    $has_val  = c_exists($db,$tbl,'value');
    if ($has_val && ($has_key || $has_name)) {
      $colKey = $has_key ? 'key' : 'name';
      $ph=[]; $bind=[];
      foreach($keys as $i=>$k){ $p=":k$i"; $ph[]=$p; $bind[$p]=$k; }
      $sql="SELECT {$colKey} AS k, value FROM {$tbl} WHERE {$colKey} IN (".implode(',',$ph).")";
      try{
        $st=$db->prepare($sql); foreach($bind as $p=>$v){ $st->bindValue($p,$v,PDO::PARAM_STR); }
        $st->execute(); $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach($keys as $want){ foreach($rows as $r){ if(($r['k']??'')===$want){ $v=(int)($r['value']??0); if($v>0) return $v; } } }
      }catch(Throwable $e){}
    }
  }
  return max(0,$sessionTenantId);
}
$sessionTenant = (int)($user['tenant_id'] ?? 0);
$tenantId = detect_tenant_id($db, $sessionTenant);

/* ---------- Date window (MTD default + dynamic chips) ---------- */
date_default_timezone_set('Africa/Cairo');
$now = new DateTime('now', new DateTimeZone('Africa/Cairo'));

$range = strtolower(trim((string)($_GET['range'] ?? 'mtd')));
if (!in_array($range,['mtd','7d','30d','custom'],true)) $range='mtd';

if ($range==='7d'){
  $fromDate = (clone $now)->modify('-6 day')->format('Y-m-d');
  $toDate   = $now->format('Y-m-d');
} elseif ($range==='30d'){
  $fromDate = (clone $now)->modify('-29 day')->format('Y-m-d');
  $toDate   = $now->format('Y-m-d');
} elseif ($range==='custom'){
  $fromDate = (string)($_GET['from'] ?? (clone $now)->modify('first day of this month')->format('Y-m-d'));
  $toDate   = (string)($_GET['to']   ?? $now->format('Y-m-d'));
} else { // MTD
  $fromDate = (clone $now)->modify('first day of this month')->format('Y-m-d');
  $toDate   = $now->format('Y-m-d');
}
$fromTS = $fromDate.' 00:00:00';
$toTS   = $toDate.' 23:59:59';

/* ---------- Feature probes ---------- */
$cashbackTbl = null; $stampsTbl = null; $hasCustomers=false; $hasOrders=false;
$custNameCol = null; $custPhoneCol = null; $orderAmtCols = [];
$ll_has_customer_id=false; $ll_has_member_id=false; $ll_has_order_id=false;

if ($db instanceof PDO) {
  $hasCustomers = t_exists($db,'customers');
  $hasOrders    = t_exists($db,'orders');
  if (t_exists($db,'loyalty_cashback_ledger')) $cashbackTbl='loyalty_cashback_ledger';
  elseif (t_exists($db,'loyalty_ledgers'))     $cashbackTbl='loyalty_ledgers';
  if (t_exists($db,'stamp_ledger'))            $stampsTbl='stamp_ledger';
  elseif (t_exists($db,'stamps_ledger'))       $stampsTbl='stamps_ledger';

  if ($hasCustomers) {
    if (c_exists($db,'customers','name'))  $custNameCol='name';
    elseif (c_exists($db,'customers','full_name')) $custNameCol='full_name';
    if (c_exists($db,'customers','phone')) $custPhoneCol='phone';
    elseif (c_exists($db,'customers','mobile')) $custPhoneCol='mobile';
    elseif (c_exists($db,'customers','email')) $custPhoneCol='email';
  }

  if ($hasOrders) {
    foreach (['total','grand_total','amount','order_total','final_amount','bill_total'] as $col) {
      if (c_exists($db,'orders',$col)) $orderAmtCols[] = "o.`{$col}`";
    }
  }

  if (t_exists($db,'loyalty_ledger')) {
    $ll_has_customer_id = c_exists($db,'loyalty_ledger','customer_id');
    $ll_has_member_id   = c_exists($db,'loyalty_ledger','member_id');
    $ll_has_order_id    = c_exists($db,'loyalty_ledger','order_id');
  }
}

/* SQL helpers */
function order_amount_sql(array $cols): string { return $cols ? 'COALESCE('.implode(',', $cols).')' : 'NULL'; }
function name_sql(?string $col): string { return $col ? "c.`{$col}`" : "NULL"; }
function phone_sql(?string $col): string { return $col ? "c.`{$col}`" : "NULL"; }
function ll_customer_ref(bool $hasCust, bool $hasMem): string { return $hasCust ? 'l.customer_id' : ($hasMem ? 'l.member_id' : 'NULL'); }

/* Data fetchers */
function fetch_points_tx(PDO $db,int $t,string $f,string $to,bool $joinCust,bool $joinOrder,string $custNameExpr,string $custPhoneExpr,string $orderAmtExpr,bool $hasCustId,bool $hasMemId,bool $hasOrderId): array{
  if (!t_exists($db,'loyalty_ledger') || !c_exists($db,'loyalty_ledger','points_delta') || !c_exists($db,'loyalty_ledger','created_at')) return [];
  $idExpr = ll_customer_ref($hasCustId,$hasMemId);
  $joins = '';
  if ($joinCust && ($hasCustId || $hasMemId)) $joins .= " LEFT JOIN customers c ON c.tenant_id=l.tenant_id AND c.id={$idExpr}";
  if ($joinOrder && $hasOrderId) $joins .= " LEFT JOIN orders o ON o.tenant_id=l.tenant_id AND o.id=l.order_id";
  $sql = "
    SELECT l.created_at AS dt, {$custNameExpr} AS customer_name, {$custPhoneExpr} AS mobile,
           {$orderAmtExpr} AS bill_amount,
           CASE WHEN l.points_delta>0 THEN l.points_delta ELSE 0 END AS issued,
           CASE WHEN l.points_delta<0 THEN ABS(l.points_delta) ELSE 0 END AS redeemed
    FROM loyalty_ledger l
    {$joins}
    WHERE l.tenant_id=:t AND l.created_at BETWEEN :f AND :to
    ORDER BY l.created_at DESC
    LIMIT 500";
  $st=$db->prepare($sql); $st->execute([':t'=>$t,':f'=>$f,':to'=>$to]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function fetch_cashback_tx(PDO $db,int $t,string $f,string $to,?string $table,bool $joinCust,bool $joinOrder,string $custNameExpr,string $custPhoneExpr,string $orderAmtExpr): array{
  if (!$table || !t_exists($db,$table) || !c_exists($db,$table,'amount') || !c_exists($db,$table,'created_at')) return [];
  $hasDir=c_exists($db,$table,'direction'); $hasCust=c_exists($db,$table,'customer_id'); $hasMem=c_exists($db,$table,'member_id'); $hasOrder=c_exists($db,$table,'order_id');
  $custRef = $hasCust ? 'l.customer_id' : ($hasMem ? 'l.member_id' : 'NULL');
  $joins=''; if ($joinCust && ($hasCust||$hasMem)) $joins.=" LEFT JOIN customers c ON c.tenant_id=l.tenant_id AND c.id={$custRef}";
  if ($joinOrder && $hasOrder) $joins.=" LEFT JOIN orders o ON o.tenant_id=l.tenant_id AND o.id=l.order_id";
  $dirCred = $hasDir ? "CASE WHEN l.direction='credit' THEN l.amount ELSE 0 END" : "CASE WHEN l.amount>0 THEN l.amount ELSE 0 END";
  $dirDeb  = $hasDir ? "CASE WHEN l.direction='debit'  THEN ABS(l.amount) ELSE 0 END" : "CASE WHEN l.amount<0 THEN ABS(l.amount) ELSE 0 END";
  $sql = "
    SELECT l.created_at AS dt, {$custNameExpr} AS customer_name, {$custPhoneExpr} AS mobile,
           {$orderAmtExpr} AS bill_amount, {$dirCred} AS credited, {$dirDeb} AS debited
    FROM {$table} l
    {$joins}
    WHERE l.tenant_id=:t AND l.created_at BETWEEN :f AND :to
    ORDER BY l.created_at DESC
    LIMIT 500";
  $st=$db->prepare($sql); $st->execute([':t'=>$t,':f'=>$f,':to'=>$to]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function fetch_stamps_tx(PDO $db,int $t,string $f,string $to,?string $table,bool $joinCust,bool $joinOrder,string $custNameExpr,string $custPhoneExpr,string $orderAmtExpr): array{
  if (!$table || !t_exists($db,$table) || !c_exists($db,$table,'created_at')) return [];
  $hasQty=c_exists($db,$table,'qty'); $hasDir=c_exists($db,$table,'direction'); $hasCust=c_exists($db,$table,'customer_id'); $hasMem=c_exists($db,$table,'member_id');
  $custRef = $hasCust ? 'l.customer_id' : ($hasMem ? 'l.member_id' : 'NULL');
  $joins=''; if ($joinCust && ($hasCust||$hasMem)) $joins.=" LEFT JOIN customers c ON c.tenant_id=l.tenant_id AND c.id={$custRef}";
  if ($joinOrder && c_exists($db,$table,'order_id') && t_exists($db,'orders')) $joins.=" LEFT JOIN orders o ON o.tenant_id=l.tenant_id AND o.id=l.order_id";
  $qty = $hasQty ? 'ABS(COALESCE(l.qty,1))' : '1'; if ($hasDir) $qty="CASE WHEN l.direction='credit' THEN {$qty} ELSE 0 END";
  $sql = "
    SELECT l.created_at AS dt, {$custNameExpr} AS customer_name, {$custPhoneExpr} AS mobile,
           {$orderAmtExpr} AS bill_amount, {$qty} AS credits
    FROM {$table} l
    {$joins}
    WHERE l.tenant_id=:t AND l.created_at BETWEEN :f AND :to
    ORDER BY l.created_at DESC
    LIMIT 500";
  $st=$db->prepare($sql); $st->execute([':t'=>$t,':f'=>$f,':to'=>$to]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function fetch_active_by_day(PDO $db,int $t,string $f,string $to): array{
  if (!t_exists($db,'loyalty_ledger') || !c_exists($db,'loyalty_ledger','created_at')) return [];
  $idCol = c_exists($db,'loyalty_ledger','customer_id') ? 'customer_id' : (c_exists($db,'loyalty_ledger','member_id') ? 'member_id' : null);
  if (!$idCol) return [];
  $st=$db->prepare("SELECT DATE(created_at) d, COUNT(DISTINCT {$idCol}) AS active FROM loyalty_ledger WHERE tenant_id=:t AND created_at BETWEEN :f AND :to GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 500");
  $st->execute([':t'=>$t,':f'=>$f,':to'=>$to]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function fetch_top_members(PDO $db,int $t,string $f,string $to,bool $hasLLCust,bool $hasLLMem,bool $hasLLOrder,bool $hasOrders,string $orderAmtExpr): array{
  if (!t_exists($db,'loyalty_ledger') || !c_exists($db,'loyalty_ledger','points_delta') || !c_exists($db,'loyalty_ledger','created_at')) return [];
  $idCol = $hasLLCust ? 'l.customer_id' : ($hasLLMem ? 'l.member_id' : null);
  if (!$idCol) return [];
  $joinOrder = ($hasLLOrder && $hasOrders && $orderAmtExpr!=='NULL') ? "LEFT JOIN orders o ON o.tenant_id=l.tenant_id AND o.id=l.order_id" : "";
  $billSel   = ($joinOrder!=='') ? $orderAmtExpr : 'NULL';
  $sql = "
    SELECT {$idCol} AS member_id,
           ABS(SUM(CASE WHEN l.points_delta<0 THEN l.points_delta ELSE 0 END)) AS redeemed_pts,
           SUM(CASE WHEN l.points_delta<0 THEN {$billSel} ELSE 0 END) AS billed_amount
    FROM loyalty_ledger l
    {$joinOrder}
    WHERE l.tenant_id=:t AND l.created_at BETWEEN :f AND :to
    GROUP BY {$idCol}
    HAVING redeemed_pts>0
    ORDER BY redeemed_pts DESC
    LIMIT 50";
  $st=$db->prepare($sql); $st->execute([':t'=>$t,':f'=>$f,':to'=>$to]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function fetch_activity(PDO $db,int $t,string $f,string $to,?string $cashTbl,?string $stampTbl): array{
  $parts=[];
  if (t_exists($db,'loyalty_ledger') && c_exists($db,'loyalty_ledger','points_delta') && c_exists($db,'loyalty_ledger','created_at')){
    $has_note=c_exists($db,'loyalty_ledger','note'); $has_order=c_exists($db,'loyalty_ledger','order_id');
    $idCol = c_exists($db,'loyalty_ledger','customer_id') ? 'customer_id' : (c_exists($db,'loyalty_ledger','member_id') ? 'member_id' : 'NULL');
    $parts[] = "SELECT 'points' AS program, l.created_at AS ts,
                CASE WHEN l.points_delta>=0 THEN 'Credit' ELSE 'Debit' END AS direction,
                ABS(l.points_delta) AS amount, ".($has_order?'l.order_id':'NULL')." AS order_id,
                {$idCol} AS customer_id, ".($has_note?'l.note':"''")." AS note
                FROM loyalty_ledger l
                WHERE l.tenant_id=:t AND l.created_at BETWEEN :f AND :to";
  }
  if ($cashTbl && t_exists($db,$cashTbl) && c_exists($db,$cashTbl,'created_at') && c_exists($db,$cashTbl,'amount')){
    $has_dir=c_exists($db,$cashTbl,'direction'); $has_note=c_exists($db,$cashTbl,'note');
    $idCol = c_exists($db,$cashTbl,'customer_id') ? 'customer_id' : (c_exists($db,$cashTbl,'member_id') ? 'member_id' : 'NULL');
    $parts[] = "SELECT 'cashback' AS program, l.created_at AS ts,
                ".($has_dir?"CASE WHEN l.direction='credit' THEN 'Credit' WHEN l.direction='debit' THEN 'Debit' ELSE l.direction END":"'Credit'")." AS direction,
                ABS(l.amount) AS amount, ".(c_exists($db,$cashTbl,'order_id')?'l.order_id':'NULL')." AS order_id,
                {$idCol} AS customer_id, ".($has_note?'l.note':"''")." AS note
                FROM {$cashTbl} l
                WHERE l.tenant_id=:t AND l.created_at BETWEEN :f AND :to";
  }
  if ($stampTbl && t_exists($db,$stampTbl) && c_exists($db,$stampTbl,'created_at')){
    $has_qty=c_exists($db,$stampTbl,'qty'); $has_dir=c_exists($db,$stampTbl,'direction'); $has_note=c_exists($db,$stampTbl,'note');
    $idCol = c_exists($db,$stampTbl,'customer_id') ? 'customer_id' : (c_exists($db,$stampTbl,'member_id') ? 'member_id' : 'NULL');
    $amt = $has_qty ? 'ABS(COALESCE(l.qty,1))' : '1'; if ($has_dir) $amt="CASE WHEN l.direction='credit' THEN {$amt} ELSE 0 END";
    $parts[] = "SELECT 'stamps' AS program, l.created_at AS ts,
                ".($has_dir?"CASE WHEN l.direction='credit' THEN 'Credit' WHEN l.direction='debit' THEN 'Debit' ELSE l.direction END":"'Credit'")." AS direction,
                {$amt} AS amount, NULL AS order_id, {$idCol} AS customer_id,
                ".($has_note?'l.note':"''")." AS note
                FROM {$stampTbl} l
                WHERE l.tenant_id=:t AND l.created_at BETWEEN :f AND :to";
  }
  if (!$parts) return [];
  $sql="SELECT * FROM (".implode(" UNION ALL ",$parts).") u ORDER BY u.ts DESC LIMIT 500";
  $st=$db->prepare($sql); $st->execute([':t'=>$t,':f'=>$f,':to'=>$to]); return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ---------- Build expressions ---------- */
$custNameExpr = name_sql($custNameCol);
$custPhoneExpr= phone_sql($custPhoneCol);
$orderAmtExpr = order_amount_sql($orderAmtCols);

/* ---------- Page datasets ---------- */
$pointsTx     = ($db instanceof PDO && $tenantId>0) ? fetch_points_tx($db,$tenantId,$fromTS,$toTS,$hasCustomers,$hasOrders,$custNameExpr,$custPhoneExpr,$orderAmtExpr,$ll_has_customer_id,$ll_has_member_id,$ll_has_order_id) : [];
$cashbackTx   = ($db instanceof PDO && $tenantId>0) ? fetch_cashback_tx($db,$tenantId,$fromTS,$toTS,$cashbackTbl,$hasCustomers,$hasOrders,$custNameExpr,$custPhoneExpr,$orderAmtExpr) : [];
$stampsTx     = ($db instanceof PDO && $tenantId>0) ? fetch_stamps_tx($db,$tenantId,$fromTS,$toTS,$stampsTbl,$hasCustomers,$hasOrders,$custNameExpr,$custPhoneExpr,$orderAmtExpr) : [];
$activeByDay  = ($db instanceof PDO && $tenantId>0) ? fetch_active_by_day($db,$tenantId,$fromTS,$toTS) : [];
$topMembers   = ($db instanceof PDO && $tenantId>0) ? fetch_top_members($db,$tenantId,$fromTS,$toTS,$ll_has_customer_id,$ll_has_member_id,$ll_has_order_id,$hasOrders,$orderAmtExpr) : [];
$activityRows = ($db instanceof PDO && $tenantId>0) ? fetch_activity($db,$tenantId,$fromTS,$toTS,$cashbackTbl,$stampsTbl) : [];

/* ---------- CSV Export ---------- */
function csv_out(string $filename, array $headers, array $rows): void {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  $out = fopen('php://output', 'w');
  fputcsv($out, $headers);
  foreach ($rows as $r) { fputcsv($out, $r); }
  fclose($out); exit;
}
if ($db instanceof PDO && isset($_GET['export']) && $tenantId>0){
  $which = strtolower(trim((string)$_GET['export']));
  if ($which==='points'){
    $csv = array_map(fn($r)=>[$r['dt'],$r['customer_name'],$r['mobile'],number_format((float)$r['bill_amount'],2,'.',''),$r['issued'],$r['redeemed']], $pointsTx);
    csv_out("points_tx_{$fromDate}_{$toDate}.csv", ['Date','Customer Name','Mobile','Bill amount','Issued','Redeemed'], $csv);
  } elseif ($which==='cashback'){
    $csv = array_map(fn($r)=>[$r['dt'],$r['customer_name'],$r['mobile'],number_format((float)$r['bill_amount'],2,'.',''),number_format((float)$r['credited'],2,'.',''),number_format((float)$r['debited'],2,'.','')], $cashbackTx);
    csv_out("cashback_tx_{$fromDate}_{$toDate}.csv", ['Date','Customer Name','Mobile','Bill amount','Credited','Debited'], $csv);
  } elseif ($which==='stamps'){
    $csv = array_map(fn($r)=>[$r['dt'],$r['customer_name'],$r['mobile'],number_format((float)$r['bill_amount'],2,'.',''),$r['credits']], $stampsTx);
    csv_out("stamps_tx_{$fromDate}_{$toDate}.csv", ['Date','Customer Name','Mobile','Bill amount','Credits'], $csv);
  } elseif ($which==='active'){
    $csv = array_map(fn($r)=>[$r['d'],$r['active']], $activeByDay);
    csv_out("active_members_by_day_{$fromDate}_{$toDate}.csv", ['Date','Active Members'], $csv);
  } elseif ($which==='top'){
    $csv = array_map(fn($r)=>[$r['member_id'],$r['redeemed_pts'], number_format((float)$r['billed_amount'],2,'.','')], $topMembers);
    csv_out("top_members_{$fromDate}_{$toDate}.csv", ['Member ID','Redeemed (pts)','Billed amount'], $csv);
  } elseif ($which==='activity'){
    $csv = array_map(fn($r)=>[$r['program'],$r['ts'],$r['direction'],$r['amount'],$r['order_id'],$r['customer_id'],$r['note']], $activityRows);
    csv_out("recent_activity_{$fromDate}_{$toDate}.csv", ['Program','Timestamp','Direction','Amount','Order','Customer','Note'], $csv);
  }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Rewards Reports · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f5f7fb;--card:#fff;--text:#0f172a;--muted:#64748b;--primary:#2563eb;--border:#e5e7eb;--hover:#eef2ff;
  --blue:#2563eb;--amber:#f59e0b;--teal:#10b981;--violet:#7c3aed;--indigo:#4f46e5;
}
*{box-sizing:border-box}
html{scroll-padding-top:80px;}
body{margin:0;background:linear-gradient(180deg,#f7fafc 0%,#f3f4f6 100%);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
.container{max-width:1200px;margin:20px auto;padding:0 16px}

/* Cards / buttons / inputs */
.card{background:var(--card);border:1px solid var(--border);border-radius:16px;box-shadow:0 8px 30px rgba(2,6,23,.06);padding:16px}
.h1{font-size:20px;font-weight:900;margin:0 0 8px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}

.btn{display:inline-flex;align-items:center;gap:8px;justify-content:center;padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:800;text-decoration:none;color:#111827;cursor:pointer}
.btn:hover{background:var(--hover)}
.btn.small{padding:6px 9px;font-size:12.5px;font-weight:800}

/* Tiles grid */
.grid{display:grid;gap:14px}
.grid-3{grid-template-columns:repeat(3,1fr)}
@media (max-width:900px){.grid-3{grid-template-columns:repeat(2,1fr)}}
@media (max-width:560px){.grid-3{grid-template-columns:1fr}}
.tile{
  display:block;text-decoration:none;color:#111827;border:1px solid var(--border);border-radius:16px;padding:16px;
  background:linear-gradient(180deg,#ffffff,#f8fbff);
  transition:transform .12s ease,box-shadow .2s ease,border-color .2s ease;
}
.tile:hover{transform:translateY(-2px);box-shadow:0 16px 40px rgba(2,6,23,.1)}
.tile .t-title{font-weight:900;margin-bottom:6px}
.tile .t-desc{color:var(--muted);font-size:13px}

/* Dynamic date filter (hidden until any report is opened) */
.filter{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.chips a{padding:7px 12px;border-radius:999px;border:1px solid var(--border);text-decoration:none;color:#111827;font-weight:800;background:#fff}
.chips a.active{background:var(--primary);border-color:var(--primary);color:#fff}
input[type="date"]{padding:8px 10px;border:1px solid var(--border);border-radius:10px;min-width:140px}

/* Panels */
.panel{display:none;border:1px solid var(--border);border-radius:16px;background:#fff;margin-top:12px;overflow:hidden;box-shadow:0 10px 30px rgba(2,6,23,.06);scroll-margin-top:80px;}
.panel.open{display:block}
.panel-hd{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(180deg,#ffffff,#f6f7ff)}
.panel-bd{padding:12px 14px}
.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-weight:800;font-size:12px;border:1px solid #e5e7eb;background:linear-gradient(180deg,#fff,#f8fafc)}
.badge.blue{border-color:color-mix(in srgb,var(--blue) 30%, var(--border));}
.badge.teal{border-color:color-mix(in srgb,var(--teal) 30%, var(--border));}
.badge.indigo{border-color:color-mix(in srgb,var(--indigo) 30%, var(--border));}
.badge.violet{border-color:color-mix(in srgb,var(--violet) 30%, var(--border));}
.badge.amber{border-color:color-mix(in srgb,var(--amber) 30%, var(--border));}

/* Tables */
.table-wrap{overflow:auto}
table{width:100%;border-collapse:separate;border-spacing:0 6px}
thead th{color:#0f172a;background:#f8fafc;text-align:left;font-weight:800;padding:8px 10px;position:sticky;top:0;z-index:1;white-space:nowrap}
tbody td{background:#fff;border:1px solid var(--border);padding:8px 10px;white-space:nowrap}
tbody tr{border-radius:12px;overflow:hidden}
.right{text-align:right}

/* Notices / Debug */
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.debug{background:#eef2ff;border:1px solid #e0e7ff;color:#1e3a8a;padding:10px;border-radius:10px;margin:10px 0;font-size:12px}
</style>
</head>
<body>

<?php
/* ===== NAV (robust include) ===== */
$active='rewards';
$nav_included=false;
$nav_paths_tried=[];
$nav1 = __DIR__ . '/../../partials/admin_nav.php';    $nav_paths_tried[]=$nav1;
if (is_file($nav1)) { $nav_included=(bool) @include $nav1; }
if (!$nav_included) {
  $nav2 = dirname(__DIR__,3) . '/partials/admin_nav.php'; $nav_paths_tried[]=$nav2;
  if (is_file($nav2)) { $nav_included=(bool) @include $nav2; }
}
if (!$nav_included) {
  $nav3 = dirname(__DIR__,4) . '/partials/admin_nav.php'; $nav_paths_tried[]=$nav3;
  if (is_file($nav3)) { $nav_included=(bool) @include $nav3; }
}
if (!$nav_included): ?>
  <div class="notice" style="max-width:1200px;margin:10px auto;">
    Navigation header not found. Looked for:
    <div style="margin-top:6px">
      <?php foreach ($nav_paths_tried as $np): ?><div><code><?= h($np) ?></code></div><?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>

  <!-- Report Blocks (top) -->
  <div class="card">
    <div class="h1">Rewards Reports</div>
    <div class="grid grid-3" id="selectors" style="margin-top:12px">
      <a class="tile" href="#r-points"   data-target="r-points"><div class="t-title">Points Performance</div><div class="t-desc">Issued & Redeemed (with CRM fields)</div></a>
      <a class="tile" href="#r-cashback" data-target="r-cashback"><div class="t-title">Cashback Wallet</div><div class="t-desc">Credits & Debits (with CRM fields)</div></a>
      <a class="tile" href="#r-stamps"   data-target="r-stamps"><div class="t-title">Stamp Cards</div><div class="t-desc">Credits (with CRM fields)</div></a>
      <a class="tile" href="#r-active"   data-target="r-active"><div class="t-title">Active Members per Day</div><div class="t-desc">Daily distincts</div></a>
      <a class="tile" href="#r-top"      data-target="r-top"><div class="t-title">Top Members</div><div class="t-desc">Redeemed points + billed total</div></a>
      <a class="tile" href="#r-activity" data-target="r-activity"><div class="t-title">Recent Activity</div><div class="t-desc">Unified feed</div></a>
    </div>
  </div>

  <!-- Dynamic Date Filter (hidden until any report is opened) -->
  <div id="filterCard" class="card" style="margin-top:12px; display:none;">
    <div class="filter">
      <div class="chips" id="rangeChips" role="tablist" aria-label="Date range">
        <a data-range="mtd"    class="<?= $range==='mtd'?'active':'' ?>"    href="#">MTD</a>
        <a data-range="7d"     class="<?= $range==='7d'?'active':'' ?>"     href="#">Last 7d</a>
        <a data-range="30d"    class="<?= $range==='30d'?'active':'' ?>"    href="#">Last 30d</a>
        <a data-range="custom" class="<?= $range==='custom'?'active':'' ?>" href="#">Custom</a>
      </div>
      <div class="row" id="customInputs" style="<?= $range==='custom'?'':'display:none' ?>">
        <input type="date" id="fromDate" value="<?= h($fromDate) ?>">
        <input type="date" id="toDate"   value="<?= h($toDate) ?>">
      </div>
    </div>
  </div>

  <!-- Points Performance -->
  <div id="r-points" class="panel">
    <div class="panel-hd">
      <div class="row"><span class="badge indigo">Points</span><strong>Performance</strong></div>
      <div class="row">
        <a class="btn small" href="?export=points&range=<?= h($range) ?>&from=<?= h($fromDate) ?>&to=<?= h($toDate) ?>">Export CSV</a>
        <a class="btn small" data-toggle="r-points">Hide/Show</a>
      </div>
    </div>
    <div class="panel-bd table-wrap">
      <table>
        <thead>
          <tr><th>Date</th><th>Customer Name</th><th>Mobile</th><th class="right">Bill amount</th><th class="right">Issued</th><th class="right">Redeemed</th></tr>
        </thead>
        <tbody>
          <?php if(!$pointsTx): ?>
            <tr><td colspan="6" style="color:#64748b">No points transactions in this window.</td></tr>
          <?php else: foreach($pointsTx as $r): ?>
            <tr>
              <td><?= h($r['dt']) ?></td>
              <td><?= $r['customer_name'] !== null ? h($r['customer_name']) : '—' ?></td>
              <td><?= $r['mobile'] !== null ? h($r['mobile']) : '—' ?></td>
              <td class="right"><?= $r['bill_amount']!==null ? nfmt($r['bill_amount'],2) : '—' ?></td>
              <td class="right"><?= nfmt($r['issued']) ?></td>
              <td class="right"><?= nfmt($r['redeemed']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Cashback Wallet -->
  <div id="r-cashback" class="panel">
    <div class="panel-hd">
      <div class="row"><span class="badge teal">Cashback</span><strong>Wallet</strong></div>
      <div class="row">
        <a class="btn small" href="?export=cashback&range=<?= h($range) ?>&from=<?= h($fromDate) ?>&to=<?= h($toDate) ?>">Export CSV</a>
        <a class="btn small" data-toggle="r-cashback">Hide/Show</a>
      </div>
    </div>
    <div class="panel-bd table-wrap">
      <table>
        <thead>
          <tr><th>Date</th><th>Customer Name</th><th>Mobile</th><th class="right">Bill amount</th><th class="right">Credited</th><th class="right">Debited</th></tr>
        </thead>
        <tbody>
          <?php if(!$cashbackTx): ?>
            <tr><td colspan="6" style="color:#64748b">No cashback transactions in this window.</td></tr>
          <?php else: foreach($cashbackTx as $r): ?>
            <tr>
              <td><?= h($r['dt']) ?></td>
              <td><?= $r['customer_name'] !== null ? h($r['customer_name']) : '—' ?></td>
              <td><?= $r['mobile'] !== null ? h($r['mobile']) : '—' ?></td>
              <td class="right"><?= $r['bill_amount']!==null ? nfmt($r['bill_amount'],2) : '—' ?></td>
              <td class="right"><?= nfmt($r['credited'],2) ?></td>
              <td class="right"><?= nfmt($r['debited'],2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Stamp Cards -->
  <div id="r-stamps" class="panel">
    <div class="panel-hd">
      <div class="row"><span class="badge amber">Stamps</span><strong>Credits</strong></div>
      <div class="row">
        <a class="btn small" href="?export=stamps&range=<?= h($range) ?>&from=<?= h($fromDate) ?>&to=<?= h($toDate) ?>">Export CSV</a>
        <a class="btn small" data-toggle="r-stamps">Hide/Show</a>
      </div>
    </div>
    <div class="panel-bd table-wrap">
      <table>
        <thead>
          <tr><th>Date</th><th>Customer Name</th><th>Mobile</th><th class="right">Bill amount</th><th class="right">Credits</th></tr>
        </thead>
        <tbody>
          <?php if(!$stampsTx): ?>
            <tr><td colspan="5" style="color:#64748b">No stamp transactions in this window.</td></tr>
          <?php else: foreach($stampsTx as $r): ?>
            <tr>
              <td><?= h($r['dt']) ?></td>
              <td><?= $r['customer_name'] !== null ? h($r['customer_name']) : '—' ?></td>
              <td><?= $r['mobile'] !== null ? h($r['mobile']) : '—' ?></td>
              <td class="right"><?= $r['bill_amount']!==null ? nfmt($r['bill_amount'],2) : '—' ?></td>
              <td class="right"><?= nfmt($r['credits']) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Active Members per Day -->
  <div id="r-active" class="panel">
    <div class="panel-hd">
      <div class="row"><span class="badge blue">Engagement</span><strong>Active Members per Day</strong></div>
      <div class="row">
        <a class="btn small" href="?export=active&range=<?= h($range) ?>&from=<?= h($fromDate) ?>&to=<?= h($toDate) ?>">Export CSV</a>
        <a class="btn small" data-toggle="r-active">Hide/Show</a>
      </div>
    </div>
    <div class="panel-bd table-wrap">
      <table>
        <thead>
          <tr><th>Date</th><th class="right">Active Members</th><th>Customer Name</th><th>Mobile</th><th>Bill</th></tr>
        </thead>
        <tbody>
          <?php if(!$activeByDay): ?>
            <tr><td colspan="5" style="color:#64748b">No activity in this window.</td></tr>
          <?php else: foreach($activeByDay as $r): ?>
            <tr>
              <td><?= h($r['d']) ?></td>
              <td class="right"><?= nfmt($r['active']) ?></td>
              <td>—</td><td>—</td><td>—</td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Top Members -->
  <div id="r-top" class="panel">
    <div class="panel-hd">
      <div class="row"><span class="badge indigo">Leaders</span><strong>Top Members by Points Redeemed</strong></div>
      <div class="row">
        <a class="btn small" href="?export=top&range=<?= h($range) ?>&from=<?= h($fromDate) ?>&to=<?= h($toDate) ?>">Export CSV</a>
        <a class="btn small" data-toggle="r-top">Hide/Show</a>
      </div>
    </div>
    <div class="panel-bd table-wrap">
      <table>
        <thead>
          <tr><th>#</th><th>Member ID</th><th class="right">Redeemed (pts)</th><th class="right">billed amount</th></tr>
        </thead>
        <tbody>
          <?php if(!$topMembers): ?>
            <tr><td colspan="4" style="color:#64748b">No redemptions found in this window.</td></tr>
          <?php else: $i=1; foreach($topMembers as $r): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td>#<?= h($r['member_id']) ?></td>
              <td class="right"><?= nfmt($r['redeemed_pts']) ?></td>
              <td class="right"><?= $r['billed_amount']!==null ? nfmt($r['billed_amount'],2) : '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Recent Activity -->
  <div id="r-activity" class="panel">
    <div class="panel-hd">
      <div class="row"><span class="badge teal">Unified</span><strong>Recent Activity (latest 500)</strong></div>
      <div class="row">
        <a class="btn small" href="?export=activity&range=<?= h($range) ?>&from=<?= h($fromDate) ?>&to=<?= h($toDate) ?>">Export CSV</a>
        <a class="btn small" data-toggle="r-activity">Hide/Show</a>
      </div>
    </div>
    <div class="panel-bd table-wrap">
      <table>
        <thead>
          <tr><th>Program</th><th>Timestamp</th><th>Direction</th><th class="right">Amount</th><th>Order</th><th>Customer</th><th>Note</th></tr>
        </thead>
        <tbody>
        <?php if(!$activityRows): ?>
          <tr><td colspan="7" style="color:#64748b">No recent activity in this window.</td></tr>
        <?php else: foreach($activityRows as $r): ?>
          <tr>
            <td><?= h($r['program']) ?></td>
            <td><?= h($r['ts']) ?></td>
            <td><?= h($r['direction']) ?></td>
            <td class="right"><?= nfmt($r['amount'], $r['program']==='cashback'?2:0) ?></td>
            <td><?= $r['order_id'] ? '#'.(int)$r['order_id'] : '—' ?></td>
            <td><?= $r['customer_id'] ? '#'.(int)$r['customer_id'] : '—' ?></td>
            <td><?= h($r['note'] ?? '') ?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php if ($DEBUG): ?>
    <div class="debug">
      <strong>Debug</strong><br>
      tenantId: <code><?= (int)$tenantId ?></code><br>
      Window: <code><?= h($fromTS) ?> → <?= h($toTS) ?></code><br>
      cashbackTbl: <code><?= h($cashbackTbl ?? 'NULL') ?></code> | stampsTbl: <code><?= h($stampsTbl ?? 'NULL') ?></code><br>
      customers: <code><?= $hasCustomers ? 'yes' : 'no' ?></code> | orders: <code><?= $hasOrders ? 'yes' : 'no' ?></code>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  const selectors = document.getElementById('selectors');
  const filterCard = document.getElementById('filterCard');
  const chips = document.getElementById('rangeChips');
  const custom = document.getElementById('customInputs');
  const fromEl = document.getElementById('fromDate');
  const toEl   = document.getElementById('toDate');

  const STORAGE_KEY = 'rewards_reports_open_one';
  function getOpenId(){ try { return localStorage.getItem(STORAGE_KEY) || ''; } catch(e){ return ''; } }
  function setOpenId(id){ try { if(id) localStorage.setItem(STORAGE_KEY,id); else localStorage.removeItem(STORAGE_KEY); } catch(e){} }

  function anyOpen(){ return !!document.querySelector('.panel.open'); }
  function updateFilterVisibility(){ if(filterCard) filterCard.style.display = anyOpen() ? '' : 'none'; }
  function closeAllPanels(){ document.querySelectorAll('.panel.open').forEach(el=>el.classList.remove('open')); }
  function openPanel(id){
    closeAllPanels();
    const el=document.getElementById(id);
    if(!el) return;
    el.classList.add('open');
    setOpenId(id);
    updateFilterVisibility();
    el.scrollIntoView({behavior:'smooth', block:'start'});
  }
  function togglePanel(id){
    const el=document.getElementById(id);
    if(!el) return;
    const isOpen = el.classList.contains('open');
    if(isOpen){
      el.classList.remove('open');
      setOpenId('');
    }else{
      closeAllPanels();
      el.classList.add('open');
      setOpenId(id);
      el.scrollIntoView({behavior:'smooth', block:'start'});
    }
    updateFilterVisibility();
  }

  // Restore last panel
  const remembered = getOpenId();
  if (remembered) {
    const el=document.getElementById(remembered);
    if(el) el.classList.add('open');
  }
  updateFilterVisibility();

  // TILE CLICKS: toggle behavior; if another is open, it closes automatically
  selectors?.addEventListener('click', function(e){
    const a = e.target.closest('a[data-target]');
    if (!a) return;
    e.preventDefault();
    const id = a.dataset.target;
    togglePanel(id);
  });

  // Header “Hide/Show” buttons: same toggle behavior
  document.querySelectorAll('[data-toggle]').forEach(btn=>{
    btn.addEventListener('click', function(e){
      e.preventDefault();
      togglePanel(this.getAttribute('data-toggle'));
    });
  });

  // Date range chips (no Apply/Clear; instant update)
  function updateRange(range, from, to){
    const u = new URL(window.location.href);
    u.searchParams.set('range', range);
    if (range === 'custom') {
      if (from) u.searchParams.set('from', from);
      if (to)   u.searchParams.set('to', to);
    } else {
      u.searchParams.delete('from'); u.searchParams.delete('to');
    }
    window.location.assign(u.toString());
  }

  chips?.addEventListener('click', function(e){
    const a = e.target.closest('a[data-range]');
    if (!a) return;
    e.preventDefault();
    const r = a.getAttribute('data-range');
    custom.style.display = (r === 'custom') ? '' : 'none';
    updateRange(r, fromEl?.value, toEl?.value);
  });
  function onDateChange(){ updateRange('custom', fromEl.value, toEl.value); }
  fromEl?.addEventListener('change', onDateChange);
  toEl?.addEventListener('change', onDateChange);
})();
</script>
</body>
</html>