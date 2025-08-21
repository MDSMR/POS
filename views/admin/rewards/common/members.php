<?php
// public_html/views/admin/rewards/common/members.php
// Members CRM list + Transactions + Manual Adjustments
// - Row menu: added Rewards Enroll/Unenroll action (posts to toggle_rewards.php)
// - Profile panel keeps its toggle; JS handler unified so both places work
// - Default date ranges: first of month → today; compact filters stay on one line

declare(strict_types=1);

/* ---------- Debug ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* ---------- Bootstrap /config/db.php (robust) ---------- */
$bootstrap_warning=''; $bootstrap_ok=false; $bootstrap_tried=[]; $bootstrap_found='';
$candA = __DIR__ . '/../../../../config/db.php'; $bootstrap_tried[]=$candA;
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
    require_once $bootstrap_found; // expects db(), use_backend_session()
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
$tenantId = (int)($user['tenant_id'] ?? 0);

/* ---------- DB ---------- */
$db = function_exists('db') ? db() : null;

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(PDO $db, string $name): bool {
  try{
    $st=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
    $st->execute([':t'=>$name]);
    return (int)$st->fetchColumn() > 0;
  }catch(Throwable $e){ return false; }
}
function col_exists(PDO $db, string $table, string $col): bool {
  try{
    $st=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c");
    $st->execute([':t'=>$table, ':c'=>$col]);
    return (int)$st->fetchColumn() > 0;
  }catch(Throwable $e){ return false; }
}
/** Bind a value only if placeholder exists in the statement SQL */
function bind_if_exists(PDOStatement $st, string $name, $value, int $pdoType = null): void {
  $sql = $st->queryString ?? '';
  if ($sql && strpos($sql, $name) !== false) {
    $st->bindValue($name, $value, $pdoType ?? (is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR));
  }
}

/* ---------- Timezone & default date windows ---------- */
date_default_timezone_set('Africa/Cairo');
$now   = new DateTime('now', new DateTimeZone('Africa/Cairo'));
$first = (clone $now)->modify('first day of this month')->setTime(0,0,0);
$todayStr = $now->format('Y-m-d');
$firstStr = $first->format('Y-m-d');

/* ---------- Feature probes ---------- */
$hasCustomers    = $db instanceof PDO ? table_exists($db,'customers') : false;
$hasOrders       = $db instanceof PDO ? table_exists($db,'orders') : false;
$hasPointsLedger = $db instanceof PDO ? table_exists($db,'loyalty_ledger') : false;

// Cashback ledger table (optional, legacy names)
$cashbackTable = null;
if ($db instanceof PDO) {
  if (table_exists($db,'loyalty_cashback_ledger')) $cashbackTable='loyalty_cashback_ledger';
  elseif (table_exists($db,'loyalty_ledgers'))     $cashbackTable='loyalty_ledgers';
}
$hasCashbackLedger = $cashbackTable !== null;

// Stamps ledger (optional)
$stampTable = null;
if ($db instanceof PDO) {
  if (table_exists($db,'stamp_ledger'))      $stampTable='stamp_ledger';
  elseif (table_exists($db,'stamps_ledger')) $stampTable='stamps_ledger';
}
$hasStampLedger = $stampTable !== null;

/* ---------- Members filters ---------- */
$mq      = trim((string)($_GET['mq'] ?? ''));       // search
$mfrom   = trim((string)($_GET['mfrom'] ?? ''));
$mto     = trim((string)($_GET['mto'] ?? ''));
$mstatus = trim((string)($_GET['mstatus'] ?? ''));  // active|inactive|''

/* Defaults if not set: first of month → today */
if ($mfrom==='') $mfrom = $firstStr;
if ($mto==='')   $mto   = $todayStr;

$mpage   = max(1,(int)($_GET['mp'] ?? 1));
$mlimit  = 20;
$moffset = ($mpage-1)*$mlimit;

/* ---------- Transactions filters ---------- */
$tq      = trim((string)($_GET['tq'] ?? ''));
$treward = strtolower(trim((string)($_GET['treward'] ?? 'all')));
if (!in_array($treward,['all','points','cashback','stamps'],true)) $treward='all';
$tfrom   = trim((string)($_GET['tfrom'] ?? ''));
$tto     = trim((string)($_GET['tto'] ?? ''));
if ($tfrom==='') $tfrom = $firstStr;
if ($tto==='')   $tto   = $todayStr;
$tpage   = max(1,(int)($_GET['tp'] ?? 1));
$tlimit  = 20;
$toffset = ($tpage-1)*$tlimit;

/* ---------- Adjustments filters ---------- */
$aq      = trim((string)($_GET['aq'] ?? ''));
$areward = strtolower(trim((string)($_GET['areward'] ?? 'all')));
if (!in_array($areward,['all','points','cashback','stamps'],true)) $areward='all';
$afrom   = trim((string)($_GET['afrom'] ?? ''));
$ato     = trim((string)($_GET['ato'] ?? ''));
if ($afrom==='') $afrom = $firstStr;
if ($ato==='')   $ato   = $todayStr;
$apage   = max(1,(int)($_GET['ap'] ?? 1));
$alimit  = 20;
$aoffset = ($apage-1)*$alimit;

/* ---------- MEMBERS QUERY (CRM view) ---------- */
$members=[]; $mTotal=0;
if ($db instanceof PDO && $hasCustomers){
  try{
    // Safe column probes
    $c_has_name       = col_exists($db,'customers','name');
    $c_has_phone      = col_exists($db,'customers','phone');
    $c_has_status     = col_exists($db,'customers','status');
    $c_has_is_active  = col_exists($db,'customers','is_active');
    $c_has_created    = col_exists($db,'customers','created_at');
    $c_has_class      = col_exists($db,'customers','classification');
    $c_has_rewards_en = col_exists($db,'customers','rewards_enrolled');

    // Active flag expression (NO alias inside expression)
    if ($c_has_is_active)      { $selActive = "COALESCE(c.is_active,1)"; }
    elseif ($c_has_status)     { $selActive = "CASE WHEN c.status='inactive' THEN 0 ELSE 1 END"; }
    else                       { $selActive = "1"; }

    $selClass  = $c_has_class ? "c.classification" : "'regular'";
    $selEnroll = $c_has_rewards_en ? "COALESCE(c.rewards_enrolled,0)" : "0";

    // Last order date & channel
    $o_has_closed  = col_exists($db,'orders','closed_at');
    $o_has_channel = col_exists($db,'orders','sales_channel');
    $selLastOrder  = $hasOrders
      ? "(SELECT MAX(COALESCE(o2.".($o_has_closed?'closed_at':'created_at').", o2.created_at))
          FROM orders o2
          WHERE o2.tenant_id=c.tenant_id AND o2.customer_id=c.id)"
      : "NULL";
    $selLastChan   = ($hasOrders && $o_has_channel)
      ? "(SELECT o3.sales_channel
          FROM orders o3
          WHERE o3.tenant_id=c.tenant_id AND o3.customer_id=c.id
          ORDER BY COALESCE(o3.".($o_has_closed?'closed_at':'created_at').", o3.created_at) DESC
          LIMIT 1)"
      : "NULL";

    // WHERE + params
    $w=["c.tenant_id = :t"];
    $p=[':t'=>$tenantId];

    if ($mq!==''){
      $like = '%'.$mq.'%';
      $w[]="(".($c_has_name?'c.name LIKE :q OR ':'').
               ($c_has_phone?'c.phone LIKE :q OR ':'').
               "CAST(c.id AS CHAR) LIKE :qraw)";
      $p[':q']=$like; $p[':qraw']=$mq;
    }
    if ($mstatus==='active'){
      if ($c_has_is_active)      { $w[]="COALESCE(c.is_active,1)=1"; }
      elseif ($c_has_status)     { $w[]="c.status <> 'inactive'"; }
    } elseif ($mstatus==='inactive'){
      if ($c_has_is_active)      { $w[]="COALESCE(c.is_active,1)=0"; }
      elseif ($c_has_status)     { $w[]="c.status = 'inactive'"; }
      else { $w[]="0=1"; }
    }
    if ($mfrom!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$mfrom) && $c_has_created){
      $w[]="c.created_at >= :mfrom"; $p[':mfrom']=$mfrom.' 00:00:00';
    }
    if ($mto!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$mto) && $c_has_created){
      $w[]="c.created_at <= :mto"; $p[':mto']=$mto.' 23:59:59';
    }

    $sql = "
      SELECT
        c.id".
        ($c_has_name  ? ", c.name"  : ", NULL AS name").
        ($c_has_phone ? ", c.phone" : ", NULL AS phone").",
        {$selActive}  AS is_active,
        {$selClass}   AS classification,
        {$selEnroll}  AS rewards_enrolled,
        {$selLastOrder} AS last_order_at,
        {$selLastChan}  AS last_channel
      FROM customers c
      WHERE ".implode(' AND ',$w)."
      ORDER BY c.id DESC
      LIMIT :off,:lim
    ";
    $st=$db->prepare($sql);
    foreach($p as $k=>$v){ bind_if_exists($st, $k, $v); }
    bind_if_exists($st, ':off', $moffset, PDO::PARAM_INT);
    bind_if_exists($st, ':lim', $mlimit , PDO::PARAM_INT);
    $st->execute();
    $members=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // total
    $sqlCount = "SELECT COUNT(*) FROM customers c WHERE ".implode(' AND ',$w);
    $ct=$db->prepare($sqlCount);
    foreach($p as $k=>$v){ bind_if_exists($ct, $k, $v); }
    $ct->execute(); $mTotal=(int)$ct->fetchColumn();
  }catch(Throwable $e){
    $bootstrap_warning = 'Members load error: '.$e->getMessage();
    $members=[]; $mTotal=0;
  }
}

/* ---------- TRANSACTIONS (unified; defensive columns) ---------- */
$tx=[]; $tTotal=0;
if ($db instanceof PDO){
  try{
    $parts=[];

    if ($hasPointsLedger && ($treward==='all' || $treward==='points')){
      $has_customer_id = col_exists($db,'loyalty_ledger','customer_id');
      $has_order_id    = col_exists($db,'loyalty_ledger','order_id');
      $has_reason      = col_exists($db,'loyalty_ledger','reason');
      $lp = "
        SELECT 'points' AS program,
               l.created_at AS ts,
               CASE WHEN l.points_delta>=0 THEN 'Add' ELSE 'Deduct' END AS direction,
               ABS(l.points_delta) AS amount,
               ".($has_order_id ? "l.order_id" : "NULL")." AS order_id,
               NULL AS user_id,
               ".($has_customer_id ? "l.customer_id" : "NULL")." AS customer_id,
               ".($has_reason ? "l.reason" : "''")." AS note
        FROM loyalty_ledger l
        WHERE l.tenant_id=:t
      ";
      if ($tfrom) $lp.=" AND l.created_at >= :pl_from";
      if ($tto)   $lp.=" AND l.created_at <= :pl_to";
      if ($tq && $has_reason) $lp.=" AND l.reason LIKE :pl_q";
      $parts[]=$lp;
    }

    if ($hasCashbackLedger && ($treward==='all' || $treward==='cashback')){
      $cb_has_dir = col_exists($db,$cashbackTable,'direction');
      $cb_has_amt = col_exists($db,$cashbackTable,'amount');
      $cb_has_order_id = col_exists($db,$cashbackTable,'order_id');
      $cb_has_user_id  = col_exists($db,$cashbackTable,'user_id');
      $cb_has_note     = col_exists($db,$cashbackTable,'note');
      $custExpr = col_exists($db,$cashbackTable,'customer_id') ? "l.customer_id" : (col_exists($db,$cashbackTable,'member_id') ? "l.member_id" : "NULL");
      $lc = "
        SELECT 'cashback' AS program,
               l.created_at AS ts,
               ".($cb_has_dir ? "CASE WHEN l.direction='credit' THEN 'Add' WHEN l.direction='debit' THEN 'Deduct' ELSE l.direction END" : "'Add'")." AS direction,
               ".($cb_has_amt ? "ABS(l.amount)" : "0")." AS amount,
               ".($cb_has_order_id ? "l.order_id" : "NULL")." AS order_id,
               ".($cb_has_user_id  ? "l.user_id"  : "NULL")." AS user_id,
               {$custExpr} AS customer_id,
               ".($cb_has_note ? "l.note" : "''")." AS note
        FROM {$cashbackTable} l
        WHERE l.tenant_id=:t
      ";
      if ($tfrom) $lc.=" AND l.created_at >= :cl_from";
      if ($tto)   $lc.=" AND l.created_at <= :cl_to";
      if ($tq && $cb_has_note) $lc.=" AND l.note LIKE :cl_q";
      $parts[]=$lc;
    }

    if ($hasStampLedger && ($treward==='all' || $treward==='stamps')){
      $s_has_qty  = col_exists($db,$stampTable,'qty');
      $s_has_dir  = col_exists($db,$stampTable,'direction');
      $s_has_note = col_exists($db,$stampTable,'note');
      $s_has_mem  = col_exists($db,$stampTable,'member_id') || col_exists($db,$stampTable,'customer_id');
      $custExprS  = col_exists($db,$stampTable,'member_id') ? "l.member_id" : (col_exists($db,$stampTable,'customer_id') ? "l.customer_id" : "NULL");
      $ls = "
        SELECT 'stamps' AS program,
               l.created_at AS ts,
               ".($s_has_dir ? "CASE WHEN l.direction='credit' THEN 'Add' WHEN l.direction='debit' THEN 'Deduct' ELSE l.direction END" : "'Add'")." AS direction,
               ".($s_has_qty ? "ABS(COALESCE(l.qty,1))" : "1")." AS amount,
               NULL AS order_id,
               NULL AS user_id,
               ".($s_has_mem ? $custExprS : "NULL")." AS customer_id,
               ".($s_has_note ? "l.note" : "''")." AS note
        FROM {$stampTable} l
        WHERE l.tenant_id=:t
      ";
      if ($tfrom) $ls.=" AND l.created_at >= :sl_from";
      if ($tto)   $ls.=" AND l.created_at <= :sl_to";
      if ($tq && $s_has_note) $ls.=" AND l.note LIKE :sl_q";
      $parts[]=$ls;
    }

    if ($parts){
      $sql = "SELECT * FROM (".implode(" UNION ALL ", $parts).") u ORDER BY u.ts DESC LIMIT :off,:lim";
      $st=$db->prepare($sql);
      bind_if_exists($st, ':t', $tenantId, PDO::PARAM_INT);
      bind_if_exists($st, ':pl_from', $tfrom.' 00:00:00');
      bind_if_exists($st, ':pl_to',   $tto.' 23:59:59');
      bind_if_exists($st, ':pl_q',    '%'.$tq.'%');
      bind_if_exists($st, ':cl_from', $tfrom.' 00:00:00');
      bind_if_exists($st, ':cl_to',   $tto.' 23:59:59');
      bind_if_exists($st, ':cl_q',    '%'.$tq.'%');
      bind_if_exists($st, ':sl_from', $tfrom.' 00:00:00');
      bind_if_exists($st, ':sl_to',   $tto.' 23:59:59');
      bind_if_exists($st, ':sl_q',    '%'.$tq.'%');
      bind_if_exists($st, ':off', $toffset, PDO::PARAM_INT);
      bind_if_exists($st, ':lim', $tlimit , PDO::PARAM_INT);
      $st->execute();
      $tx=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $cntSql = "SELECT SUM(cn) FROM (".implode(" UNION ALL ", array_map(fn($q)=>"SELECT COUNT(*) AS cn FROM (".$q.") y",$parts)).") z";
      $ct=$db->prepare($cntSql);
      bind_if_exists($ct, ':t', $tenantId, PDO::PARAM_INT);
      bind_if_exists($ct, ':pl_from', $tfrom.' 00:00:00');
      bind_if_exists($ct, ':pl_to',   $tto.' 23:59:59');
      bind_if_exists($ct, ':pl_q',    '%'.$tq.'%');
      bind_if_exists($ct, ':cl_from', $tfrom.' 00:00:00');
      bind_if_exists($ct, ':cl_to',   $tto.' 23:59:59');
      bind_if_exists($ct, ':cl_q',    '%'.$tq.'%');
      bind_if_exists($ct, ':sl_from', $tfrom.' 00:00:00');
      bind_if_exists($ct, ':sl_to',   $tto.' 23:59:59');
      bind_if_exists($ct, ':sl_q',    '%'.$tq.'%');
      $ct->execute(); $tTotal = (int)$ct->fetchColumn();
    }
  }catch(Throwable $e){
    $bootstrap_warning = $bootstrap_warning ?: ('Transactions load error: '.$e->getMessage());
  }
}

/* ---------- ADJUSTMENTS list (manual only) ---------- */
$adj=[]; $aTotal=0;
if ($db instanceof PDO){
  try{
    $parts=[];

    if ($hasPointsLedger && ($areward==='all' || $areward==='points')){
      $pl_has_order = col_exists($db,'loyalty_ledger','order_id');
      $pl_has_reason= col_exists($db,'loyalty_ledger','reason');
      $ap = "
        SELECT 'points' AS program,
               l.created_at AS ts,
               CASE WHEN l.points_delta>=0 THEN 'Add' ELSE 'Deduct' END AS direction,
               ABS(l.points_delta) AS amount,
               l.customer_id AS customer_id,
               ".($pl_has_reason ? "l.reason" : "''")." AS note
        FROM loyalty_ledger l
        WHERE l.tenant_id=:t ".($pl_has_order ? "AND (l.order_id IS NULL OR l.order_id=0)" : "");
      if ($afrom) $ap.=" AND l.created_at >= :apl_from";
      if ($ato)   $ap.=" AND l.created_at <= :apl_to";
      if ($aq && $pl_has_reason) $ap.=" AND l.reason LIKE :apl_q";
      $parts[]=$ap;
    }

    if ($hasCashbackLedger && ($areward==='all' || $areward==='cashback')){
      $cb_has_order = col_exists($db,$cashbackTable,'order_id');
      $cb_has_note  = col_exists($db,$cashbackTable,'note');
      $cb_has_dir   = col_exists($db,$cashbackTable,'direction');
      $cb_has_amt   = col_exists($db,$cashbackTable,'amount');
      $custExprC    = col_exists($db,$cashbackTable,'customer_id') ? "l.customer_id" : (col_exists($db,$cashbackTable,'member_id') ? "l.member_id" : "NULL");
      $ac = "
        SELECT 'cashback' AS program,
               l.created_at AS ts,
               ".($cb_has_dir ? "CASE WHEN l.direction='credit' THEN 'Add' WHEN l.direction='debit' THEN 'Deduct' ELSE l.direction END" : "'Add'")." AS direction,
               ".($cb_has_amt ? "ABS(l.amount)" : "0")." AS amount,
               {$custExprC} AS customer_id,
               ".($cb_has_note ? "l.note" : "''")." AS note
        FROM {$cashbackTable} l
        WHERE l.tenant_id=:t ".($cb_has_order ? "AND (l.order_id IS NULL OR l.order_id=0)" : "");
      if ($afrom) $ac.=" AND l.created_at >= :acl_from";
      if ($ato)   $ac.=" AND l.created_at <= :acl_to";
      if ($aq && $cb_has_note) $ac.=" AND l.note LIKE :acl_q";
      $parts[]=$ac;
    }

    if ($hasStampLedger && ($areward==='all' || $areward==='stamps')){
      $s_has_note = col_exists($db,$stampTable,'note');
      $s_has_dir  = col_exists($db,$stampTable,'direction');
      $s_has_qty  = col_exists($db,$stampTable,'qty');
      $s_has_mem  = col_exists($db,$stampTable,'member_id') || col_exists($db,$stampTable,'customer_id');
      $custExprS  = col_exists($db,$stampTable,'member_id') ? "l.member_id" : (col_exists($db,$stampTable,'customer_id') ? "l.customer_id" : "NULL");
      $as = "
        SELECT 'stamps' AS program,
               l.created_at AS ts,
               ".($s_has_dir ? "CASE WHEN l.direction='credit' THEN 'Add' WHEN l.direction='debit' THEN 'Deduct' ELSE l.direction END" : "'Add'")." AS direction,
               ".($s_has_qty ? "ABS(COALESCE(l.qty,1))" : "1")." AS amount,
               {$custExprS} AS customer_id,
               ".($s_has_note ? "l.note" : "''")." AS note
        FROM {$stampTable} l
        WHERE l.tenant_id=:t
      ";
      if ($afrom) $as.=" AND l.created_at >= :asl_from";
      if ($ato)   $as.=" AND l.created_at <= :asl_to";
      if ($aq && $s_has_note) $as.=" AND l.note LIKE :asl_q";
      $parts[]=$as;
    }

    if ($parts){
      $sql = "SELECT * FROM (".implode(" UNION ALL ", $parts).") u ORDER BY u.ts DESC LIMIT :off,:lim";
      $st=$db->prepare($sql);
      bind_if_exists($st, ':t', $tenantId, PDO::PARAM_INT);
      bind_if_exists($st, ':apl_from', $afrom.' 00:00:00');
      bind_if_exists($st, ':apl_to',   $ato.' 23:59:59');
      bind_if_exists($st, ':apl_q',    '%'.$aq.'%');
      bind_if_exists($st, ':acl_from', $afrom.' 00:00:00');
      bind_if_exists($st, ':acl_to',   $ato.' 23:59:59');
      bind_if_exists($st, ':acl_q',    '%'.$aq.'%');
      bind_if_exists($st, ':asl_from', $afrom.' 00:00:00');
      bind_if_exists($st, ':asl_to',   $ato.' 23:59:59');
      bind_if_exists($st, ':asl_q',    '%'.$aq.'%');
      bind_if_exists($st, ':off', $aoffset, PDO::PARAM_INT);
      bind_if_exists($st, ':lim', $alimit , PDO::PARAM_INT);
      $st->execute();
      $adj=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

      $cntSql = "SELECT SUM(cn) FROM (".implode(" UNION ALL ", array_map(fn($q)=>"SELECT COUNT(*) AS cn FROM (".$q.") y",$parts)).") z";
      $ct=$db->prepare($cntSql);
      bind_if_exists($ct, ':t', $tenantId, PDO::PARAM_INT);
      bind_if_exists($ct, ':apl_from', $afrom.' 00:00:00');
      bind_if_exists($ct, ':apl_to',   $ato.' 23:59:59');
      bind_if_exists($ct, ':apl_q',    '%'.$aq.'%');
      bind_if_exists($ct, ':acl_from', $afrom.' 00:00:00');
      bind_if_exists($ct, ':acl_to',   $ato.' 23:59:59');
      bind_if_exists($ct, ':acl_q',    '%'.$aq.'%');
      bind_if_exists($ct, ':asl_from', $afrom.' 00:00:00');
      bind_if_exists($ct, ':asl_to',   $ato.' 23:59:59');
      bind_if_exists($ct, ':asl_q',    '%'.$aq.'%');
      $ct->execute(); $aTotal = (int)$ct->fetchColumn();
    }
  }catch(Throwable $e){
    $bootstrap_warning = $bootstrap_warning ?: ('Adjustments load error: '.$e->getMessage());
  }
}

/* ---------- Paging helpers ---------- */
function pages(int $total, int $limit): int { return max(1,(int)ceil($total/$limit)); }
function pager(string $key, int $page, int $pages): string{
  if ($pages<=1) return '';
  $base = strtok($_SERVER['REQUEST_URI'],'?'); $qs = $_GET; $out='<div class="pager">';
  if ($page>1){ $qs[$key]=$page-1; $out.='<a class="btn" href="'.h($base.'?'.http_build_query($qs)).'">Prev</a>'; }
  $out.='<span class="muted" style="padding:6px 8px;">Page '.(int)$page.' of '.(int)$pages.'</span>';
  if ($page<$pages){ $qs[$key]=$page+1; $out.='<a class="btn" href="'.h($base.'?'.http_build_query($qs)).'">Next</a>'; }
  return $out.'</div>';
}

/* ---------- Small helpers ---------- */
function classification_badge(?string $c): string{
  $c = strtolower((string)$c);
  $map = [
    'vip'       => ['VIP',        '#f59e0b', '#78350f'],
    'corporate' => ['Corporate',  '#14b8a6', '#0f766e'],
    'blocked'   => ['Blocked',    '#ef4444', '#7f1d1d'],
    'regular'   => ['Regular',    '#9ca3af', '#374151'],
    ''          => ['Regular',    '#9ca3af', '#374151'],
  ];
  [$label,$bg,$fg] = $map[$c] ?? $map['regular'];
  return '<span class="chip" style="background:'.$bg.'20;color:'.$fg.';border:1px solid '.$bg.'40;">'.$label.'</span>';
}
function rewards_badge($enrolled): string{
  $is = (int)$enrolled===1;
  $bg = $is ? '#10b981' : '#9ca3af';
  $fg = $is ? '#065f46' : '#374151';
  $tx = $is ? 'Enrolled' : 'Not Enrolled';
  return '<span class="chip" style="background:'.$bg.'20;color:'.$fg.';border:1px solid '.$bg.'40;">'.$tx.'</span>';
}
function channel_icon(?string $ch): string{
  $ch = strtolower((string)$ch);
  $icon = [
    'pos'      => '🖥️',
    'web'      => '🌐',
    'app'      => '📱',
    'delivery' => '🏍️',
    'phone'    => '☎️',
    'other'    => '➕',
    ''         => '➕',
  ][$ch] ?? '➕';
  return $icon;
}

?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Members · Rewards · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb;--hover:#f3f4f6}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
.container{max-width:1200px;margin:20px auto;padding:0 16px}

/* Cards / buttons / inputs */
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);margin-top:14px}
.card .hd{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap}
.card .bd{padding:12px 14px}
.h1{font-size:18px;font-weight:800;margin:0 0 6px}
.muted{color:var(--muted);font-size:13px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.row.compact{gap:8px}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:700;text-decoration:none;color:#111827;cursor:pointer}
.btn:hover{background:var(--hover)}
.btn.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn.small{padding:6px 9px;font-weight:700;font-size:12.5px}
input[type="text"],input[type="number"],input[type="date"],input[type="search"],input[type="tel"],input[type="email"],select,textarea{
  width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-size:14px
}
select.slim{max-width:150px}

/* Filters */
#mFilters input[type="search"]{min-width:240px;max-width:260px}
#mFilters input[type="date"]{max-width:140px}
#mFilters select.slim{max-width:140px}

/* Tighter widths for Transactions & Adjustments (so one line) */
#tFilters input[type="search"], #aFilters input[type="search"]{min-width:180px;max-width:200px}
#tFilters input[type="date"],   #aFilters input[type="date"]  {max-width:140px}
#tFilters select.slim,          #aFilters select.slim          {max-width:140px}
@media (min-width: 768px){
  #mFilters, #tFilters, #aFilters {flex-wrap:nowrap}
}

/* Table */
.table-wrap{overflow:auto}
table{width:100%;border-collapse:separate;border-spacing:0 8px}
th,td{font-size:14px;padding:10px 12px;vertical-align:middle;white-space:nowrap}
thead th{color:var(--muted);text-align:left}
tbody tr{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
tbody td{border-top:1px solid var(--border)}
.right{text-align:right}

/* Chips & menus */
.chip{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;font-weight:700;font-size:12.5px}
.actions{position:relative}
.menu{position:absolute;right:0;top:36px;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.08);display:none;min-width:200px;z-index:30}
.menu.open{display:block}
.menu a, .menu button{display:block;width:100%;text-align:left;padding:10px 12px;border:none;background:transparent;color:#111827;font-weight:600;cursor:pointer}
.menu a:hover, .menu button:hover{background:var(--hover)}

/* Notices / pager */
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.pager{display:flex;gap:8px;align-items:center;margin-top:8px}

/* Slide-over modal shared */
.panel-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.52);display:none;z-index:49}
.panel{position:fixed;top:0;right:0;height:100%;width:560px;max-width:100%;background:#fff;border-left:1px solid var(--border);transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;z-index:50}
.panel.open + .panel-backdrop{display:block}
.panel.open{transform:translateX(0)}
.panel-hd{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-bd{padding:12px 14px;overflow:auto}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:640px){.form-grid{grid-template-columns:1fr}}
.helper{font-size:12.5px;color:#6b7280;margin-top:6px}

.name-link{color:#111827;text-decoration:none;font-weight:800}
.name-link:hover{text-decoration:underline}
</style>
</head>
<body>

<?php
/* Admin nav include (safe) */
$active='rewards_members';
$nav_included=false;
$nav1 = __DIR__ . '/../../partials/admin_nav.php';
$nav2 = dirname(__DIR__,3) . '/partials/admin_nav.php';
if (is_file($nav1)) { $nav_included=(bool) @include $nav1; }
elseif (is_file($nav2)) { $nav_included=(bool) @include $nav2; }
?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>

  <!-- MEMBERS (CRM list) -->
  <div class="card" style="margin-top:0;">
    <div class="hd">
      <div>
        <div class="h1">Members</div>
        <div class="muted">Quick CRM view with Classification and Rewards enrollment.</div>
      </div>
      <div class="row">
        <button class="btn small primary" id="openCreate">Add Member</button>
      </div>
    </div>
    <div class="bd">
      <form id="mFilters" class="row compact" role="search" onsubmit="return false">
        <input type="search" name="mq" placeholder="Search name / phone / ID…" value="<?= h($mq) ?>">
        <select name="mstatus" class="slim">
          <option value="">All statuses</option>
          <option value="active"   <?= $mstatus==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $mstatus==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        <input type="date" name="mfrom" value="<?= h($mfrom) ?>">
        <input type="date" name="mto"   value="<?= h($mto) ?>">
      </form>

      <div class="table-wrap" style="margin-top:10px;">
        <table>
          <thead>
            <tr>
              <th style="min-width:70px;">ID</th>
              <th style="min-width:180px;">Name</th>
              <th style="min-width:140px;">Mobile</th>
              <th style="min-width:120px;">Classification</th>
              <th style="min-width:130px;">Rewards</th>
              <th style="min-width:160px;">Last order</th>
              <th style="width:100px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$members): ?>
              <tr><td colspan="7" style="padding:16px">No members found.</td></tr>
            <?php else: foreach($members as $m):
              $id   = (int)$m['id'];
              $name = ($m['name'] ?? '') !== '' ? $m['name'] : ('ID #'.$id);
              $chan = $m['last_channel'] ?? null;
              $chanIcon = channel_icon($chan);
              $lastAt = $m['last_order_at'] ? date('Y-m-d', strtotime((string)$m['last_order_at'])) : '—';
              $isActive = (int)($m['is_active'] ?? 1) === 1;
              $isEnrolled = (int)($m['rewards_enrolled'] ?? 0) === 1;
            ?>
              <tr data-id="<?= $id ?>">
                <td style="border-top:none">#<?= $id ?></td>
                <td style="border-top:none"><a href="#" class="name-link" data-open-profile="<?= $id ?>"><?= h($name) ?></a></td>
                <td style="border-top:none"><?= h($m['phone'] ?? '—') ?></td>
                <td style="border-top:none"><?= classification_badge($m['classification'] ?? 'regular') ?></td>
                <td style="border-top:none"><?= rewards_badge($m['rewards_enrolled'] ?? 0) ?></td>
                <td style="border-top:none"><?= $chanIcon ?> <?= h($lastAt) ?></td>
                <td style="border-top:none">
                  <div class="actions">
                    <button class="btn small" data-menu="<?= $id ?>">⋯</button>
                    <div class="menu" id="menu-<?= $id ?>">
                      <button type="button" data-open-profile="<?= $id ?>">View profile</button>
                      <a href="/views/admin/rewards/members/edit.php?id=<?= $id ?>">Edit</a>
                      <button type="button" data-rewards-toggle="<?= $isEnrolled ? 'unenroll' : 'enroll' ?>" data-member-id="<?= $id ?>">
                        <?= $isEnrolled ? "Unenroll Rewards" : "Enroll Rewards" ?>
                      </button>
                      <button type="button" data-toggle-active='<?= $isActive ? "deactivate" : "activate" ?>'>
                        <?= $isActive ? "Deactivate" : "Activate" ?>
                      </button>
                    </div>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?= pager('mp', $mpage, pages($mTotal,$mlimit)); ?>
      </div>
    </div>
  </div>

  <!-- TRANSACTIONS -->
  <div class="card">
    <div class="hd">
      <strong>Transactions</strong>
      <div class="row">
        <a class="btn small primary" href="<?php
          $base = strtok($_SERVER['REQUEST_URI'],'?'); $qs = $_GET; $qs['export']='tx';
          echo h($base.'?'.http_build_query($qs));
        ?>">Export CSV</a>
      </div>
    </div>
    <div class="bd">
      <form id="tFilters" class="row compact" role="search" onsubmit="return false">
        <select name="treward" class="slim">
          <option value="all"      <?= $treward==='all'?'selected':'' ?>>All</option>
          <option value="points"   <?= $treward==='points'?'selected':'' ?>>Points</option>
          <option value="cashback" <?= $treward==='cashback'?'selected':'' ?>>Cashback</option>
          <option value="stamps"   <?= $treward==='stamps'?'selected':'' ?>>Stamps</option>
        </select>
        <input type="search" name="tq" placeholder="Search note…" value="<?= h($tq) ?>">
        <input type="date" name="tfrom" value="<?= h($tfrom) ?>">
        <input type="date" name="tto"   value="<?= h($tto) ?>">
      </form>

      <div class="table-wrap" style="margin-top:10px;">
        <table>
          <thead>
            <tr>
              <th>Program</th>
              <th>Date</th>
              <th>Dir</th>
              <th class="right">Amt</th>
              <th>Order</th>
              <th>User</th>
              <th>Customer</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$tx): ?>
              <tr><td colspan="8" style="padding:16px">No transactions found.</td></tr>
            <?php else: foreach($tx as $r): ?>
              <tr>
                <td style="border-top:none"><?= h(ucfirst($r['program'])) ?></td>
                <td style="border-top:none"><?= h($r['ts']) ?></td>
                <td style="border-top:none"><?= h($r['direction']) ?></td>
                <td class="right" style="border-top:none"><?= number_format((float)$r['amount'],2,'.','') ?></td>
                <td style="border-top:none"><?= !empty($r['order_id']) ? '#'.(int)$r['order_id'] : '—' ?></td>
                <td style="border-top:none"><?= !empty($r['user_id']) ? '#'.(int)$r['user_id'] : '—' ?></td>
                <td style="border-top:none"><?= !empty($r['customer_id']) ? '#'.(int)$r['customer_id'] : '—' ?></td>
                <td style="border-top:none"><?= h($r['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?= pager('tp', $tpage, pages($tTotal,$tlimit)); ?>
      </div>
    </div>
  </div>

  <!-- MANUAL ADJUSTMENTS -->
  <div class="card">
    <div class="hd">
      <strong>Manual Adjustments</strong>
      <div class="row">
        <button class="btn small primary" id="openAdj">Add Adjustment</button>
      </div>
    </div>
    <div class="bd">
      <form id="aFilters" class="row compact" role="search" onsubmit="return false">
        <select name="areward" class="slim">
          <option value="all"      <?= $areward==='all'?'selected':'' ?>>All</option>
          <option value="points"   <?= $areward==='points'?'selected':'' ?>>Points</option>
          <option value="cashback" <?= $areward==='cashback'?'selected':'' ?>>Cashback</option>
          <option value="stamps"   <?= $areward==='stamps'?'selected':'' ?>>Stamps</option>
        </select>
        <input type="search" name="aq" placeholder="Search note…" value="<?= h($aq) ?>">
        <input type="date" name="afrom" value="<?= h($afrom) ?>">
        <input type="date" name="ato"   value="<?= h($ato) ?>">
      </form>

      <div class="table-wrap" style="margin-top:10px;">
        <table>
          <thead>
            <tr>
              <th>Program</th>
              <th>Date</th>
              <th>Dir</th>
              <th class="right">Amt</th>
              <th>Customer</th>
              <th>Note</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$adj): ?>
              <tr><td colspan="6" style="padding:16px">No adjustments found.</td></tr>
            <?php else: foreach($adj as $r): ?>
              <tr>
                <td style="border-top:none"><?= h(ucfirst($r['program'])) ?></td>
                <td style="border-top:none"><?= h($r['ts']) ?></td>
                <td style="border-top:none"><?= h($r['direction']) ?></td>
                <td class="right" style="border-top:none"><?= number_format((float)$r['amount'],2,'.','') ?></td>
                <td style="border-top:none"><?= !empty($r['customer_id']) ? '#'.(int)$r['customer_id'] : '—' ?></td>
                <td style="border-top:none"><?= h($r['note'] ?? '') ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
        <?= pager('ap', $apage, pages($aTotal,$alimit)); ?>
      </div>
    </div>
  </div>
</div>

<!-- Slide-over: Add Member -->
<div id="createModal" class="panel" aria-hidden="true">
  <div class="panel-hd">
    <strong>Add Member</strong>
    <button class="btn small" id="createClose" type="button">Close</button>
  </div>
  <div class="panel-bd">
    <form id="createForm" method="post" action="/controllers/admin/rewards/members/create.php">
      <div class="form-grid">
        <div>
          <label class="muted">Full name</label>
          <input type="text" name="name" placeholder="e.g., Sarah Khaled" required>
        </div>
        <div>
          <label class="muted">Mobile</label>
          <input type="tel" name="phone" inputmode="tel" placeholder="e.g., 0100 000 0000">
        </div>
        <div>
          <label class="muted">Email</label>
          <input type="email" name="email" placeholder="name@example.com">
        </div>
        <div>
          <label class="muted">Status</label>
          <select name="status">
            <option value="active" selected>Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>
      </div>
      <div class="row" style="justify-content:center;margin-top:12px;">
        <button class="btn" type="button" id="createCancel">Cancel</button>
        <button class="btn primary" type="submit">Create</button>
      </div>
    </form>
  </div>
</div>
<div id="backdrop" class="panel-backdrop" aria-hidden="true"></div>

<!-- Slide-over: Profile (loaded via fetch) -->
<div id="profileModal" class="panel" aria-hidden="true">
  <div class="panel-hd">
    <strong>Member Profile</strong>
    <button class="btn small" id="profileClose" type="button">Close</button>
  </div>
  <div class="panel-bd" id="profileBody">
    <div class="helper">Loading…</div>
  </div>
</div>

<!-- Slide-over: Add Manual Adjustment -->
<div id="adjModal" class="panel" aria-hidden="true">
  <div class="panel-hd">
    <strong>Add Manual Adjustment</strong>
    <button class="btn small" id="adjClose" type="button">Close</button>
  </div>
  <div class="panel-bd">
    <form id="adjForm" method="post" action="/controllers/admin/rewards/common/adjustment_create.php">
      <div class="form-grid">
        <div>
          <label class="muted">Program</label>
          <select name="program">
            <option value="points">Points</option>
            <option value="cashback">Cashback</option>
            <option value="stamps">Stamps</option>
          </select>
        </div>
        <div>
          <label class="muted">Type</label>
          <select name="type">
            <option value="credit">Add</option>
            <option value="debit">Deduct</option>
          </select>
        </div>
        <div>
          <label class="muted">Customer ID</label>
          <input type="number" name="member_id" placeholder="e.g., 10021" min="1" required>
        </div>
        <div>
          <label class="muted">Amount</label>
          <input type="number" step="0.01" min="0.01" name="amount" placeholder="e.g., 25" required>
        </div>
        <div style="grid-column:1/-1">
          <label class="muted">Reason</label>
          <input type="text" name="reason" placeholder="Internal note" required>
        </div>
      </div>
      <div class="row" style="justify-content:center;margin-top:12px;">
        <button class="btn" type="button" id="adjCancel">Cancel</button>
        <button class="btn primary" type="submit">Save</button>
      </div>
    </form>
    <div id="adjMsg" class="notice" style="display:none;margin-top:10px"></div>
  </div>
</div>

<script>
(function(){
  function updateQuery(params, pagingKeyToReset){
    const u = new URL(window.location.href);
    Object.keys(params).forEach(k=>{
      if (params[k]===null || params[k]==='') u.searchParams.delete(k);
      else u.searchParams.set(k, params[k]);
    });
    if (pagingKeyToReset) u.searchParams.set(pagingKeyToReset, '1');
    window.location.assign(u.toString());
  }
  function debounce(fn, ms){ let t=null; return function(){ const a=arguments; clearTimeout(t); t=setTimeout(()=>fn.apply(this,a), ms); }; }

  /* Filters */
  const mForm = document.getElementById('mFilters');
  if (mForm){
    mForm.addEventListener('input', debounce(()=> {
      const fd = new FormData(mForm);
      updateQuery({
        mq: (fd.get('mq')||''),
        mstatus: (fd.get('mstatus')||''),
        mfrom: (fd.get('mfrom')||''),
        mto: (fd.get('mto')||'')
      }, 'mp');
    }, 300));
  }

  const tForm = document.getElementById('tFilters');
  if (tForm){
    tForm.addEventListener('input', debounce(()=> {
      const fd = new FormData(tForm);
      updateQuery({
        treward:(fd.get('treward')||''),
        tq:(fd.get('tq')||''),
        tfrom:(fd.get('tfrom')||''),
        tto:(fd.get('tto')||'')
      }, 'tp');
    }, 300));
  }

  const aForm = document.getElementById('aFilters');
  if (aForm){
    aForm.addEventListener('input', debounce(()=> {
      const fd = new FormData(aForm);
      updateQuery({
        areward:(fd.get('areward')||''),
        aq:(fd.get('aq')||''),
        afrom:(fd.get('afrom')||''),
        ato:(fd.get('ato')||'')
      }, 'ap');
    }, 300));
  }

  /* Slide-overs */
  const backdrop = document.getElementById('backdrop');
  function openPanel(p){ p.classList.add('open'); p.setAttribute('aria-hidden','false'); backdrop.style.display='block'; }
  function closePanel(p){ p.classList.remove('open'); p.setAttribute('aria-hidden','true'); backdrop.style.display='none'; }

  // Create
  const createModal = document.getElementById('createModal');
  document.getElementById('openCreate')?.addEventListener('click',()=>openPanel(createModal));
  document.getElementById('createClose')?.addEventListener('click',()=>closePanel(createModal));
  document.getElementById('createCancel')?.addEventListener('click',()=>closePanel(createModal));

  // Profile
  const profileModal = document.getElementById('profileModal');
  const profileBody  = document.getElementById('profileBody');
  document.getElementById('profileClose')?.addEventListener('click',()=>closePanel(profileModal));

  function loadProfile(id){
    profileBody.innerHTML = '<div class="helper">Loading…</div>';
    openPanel(profileModal);
    fetch('/controllers/admin/rewards/members/profile.php?id='+encodeURIComponent(id), {credentials:'same-origin'})
      .then(r=> r.ok ? r.text() : Promise.reject(new Error('Profile endpoint unavailable')))
      .then(html => { profileBody.innerHTML = html || '<div class="helper">No profile details.</div>'; })
      .catch(()=>{ profileBody.innerHTML = '<div class="helper">Profile view coming soon.</div>'; });
  }

  document.body.addEventListener('click', (e)=>{
    const a = e.target.closest('[data-open-profile]');
    if (a){ e.preventDefault(); loadProfile(a.getAttribute('data-open-profile')); }
  });

  // Menus
  document.body.addEventListener('click', (e)=>{
    const trigger = e.target.closest('[data-menu]');
    if (trigger){
      e.preventDefault();
      const id = trigger.getAttribute('data-menu');
      document.querySelectorAll('.menu.open').forEach(m=>m.classList.remove('open'));
      document.getElementById('menu-'+id)?.classList.add('open');
    } else if (!e.target.closest('.menu')){
      document.querySelectorAll('.menu.open').forEach(m=>m.classList.remove('open'));
    }
  });

  // Toggle active (posts to controller)
  document.body.addEventListener('click', (e)=>{
    const btn = e.target.closest('[data-toggle-active]');
    if (!btn) return;
    e.preventDefault();
    const tr = btn.closest('tr');
    const id = tr?.getAttribute('data-id');
    const action = btn.getAttribute('data-toggle-active'); // 'activate' | 'deactivate'
    fetch('/controllers/admin/rewards/members/toggle_status.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'id='+encodeURIComponent(id)+'&action='+encodeURIComponent(action)
    })
    .then(r=>r.json()).then(js=>{
      if (js && js.ok){ location.reload(); }
      else { throw new Error(js && js.error ? js.error : 'Failed'); }
    })
    .catch(err=>{ alert('Toggle failed: '+String(err)); });
  });

  // Unified Rewards Enroll/Unenroll (works from list menu and profile panel)
  document.body.addEventListener('click', (e)=>{
    const t = e.target.closest('[data-rewards-toggle]');
    if (!t) return;
    e.preventDefault();
    const id = t.getAttribute('data-member-id') || t.closest('tr')?.getAttribute('data-id');
    const action = t.getAttribute('data-rewards-toggle'); // 'enroll' | 'unenroll'
    const inProfile = !!t.closest('#profileModal');

    fetch('/controllers/admin/rewards/members/toggle_rewards.php', {
      method:'POST',
      credentials:'same-origin',
      headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:'id='+encodeURIComponent(id)+'&action='+encodeURIComponent(action)
    }).then(r=>r.json())
      .then(js=>{
        if (js && js.ok){
          if (inProfile){ loadProfile(id); }
          else { location.reload(); }
        } else {
          throw new Error(js && js.error ? js.error : 'Failed');
        }
      })
      .catch(err=> alert('Rewards toggle failed: '+String(err)));
  });

  // Adjustments modal
  const adjModal = document.getElementById('adjModal');
  document.getElementById('openAdj')?.addEventListener('click',()=>openPanel(adjModal));
  document.getElementById('adjClose')?.addEventListener('click',()=>closePanel(adjModal));
  document.getElementById('adjCancel')?.addEventListener('click',()=>closePanel(adjModal));

  backdrop.addEventListener('click', ()=>{
    [createModal, profileModal, adjModal].forEach(m=>{ if (m) { m.classList.remove('open'); m.setAttribute('aria-hidden','true'); } });
    backdrop.style.display='none';
  });
  document.addEventListener('keydown',(e)=>{ if(e.key==='Escape'){ backdrop.click(); } });

  // Ajax for Add Manual Adjustment (stay on page)
  const adjForm = document.getElementById('adjForm');
  const adjMsg  = document.getElementById('adjMsg');
  adjForm?.addEventListener('submit', function(e){
    e.preventDefault();
    adjMsg.style.display='none';
    const fd = new FormData(adjForm);
    fetch(adjForm.action, {method:'POST', body:fd, credentials:'same-origin'})
      .then(r=>r.json())
      .then(js=>{
        if (js && js.ok){ adjMsg.textContent='Saved.'; adjMsg.style.display='block'; setTimeout(()=>location.reload(), 650); }
        else { throw new Error(js && js.error ? js.error : 'Failed'); }
      })
      .catch(err=>{ adjMsg.textContent='Failed: '+String(err); adjMsg.style.display='block'; });
  });
})();
</script>
</body>
</html>