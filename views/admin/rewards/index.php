<?php
// public_html/views/admin/rewards/index.php — Rewards landing (colorful tiles + KPIs)
// - tenant_id is read dynamically from Settings (safe probes) with fallback to session
// - KPI tiles (MTD) link to Members/Transactions with filters pre-filled
// - Colorful program tiles (Points, Stamp, Cashback, Common, Reports)
// - Robust bootstrap/session like the rest of the admin

declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

$bootstrap_warning = ''; $bootstrap_ok = false;

/* Resolve /config/db.php */
$bootstrap_tried = [];
$bootstrap_path  = __DIR__ . '/../../../config/db.php'; // up 3 → /public_html/config/db.php
$bootstrap_tried[] = $bootstrap_path;
if (!is_file($bootstrap_path)) {
  $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  if ($docRoot !== '') {
    $alt = $docRoot . '/config/db.php';
    $bootstrap_tried[] = $alt;
    if (is_file($alt)) { $bootstrap_path = $alt; }
  }
}
if (!is_file($bootstrap_path)) {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try{
    require_once $bootstrap_path; // db(), use_backend_session()
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok = true; }
  } catch (Throwable $e) {
    $bootstrap_warning = 'Bootstrap error: '.$e->getMessage();
  } finally { if ($prevHandler) set_error_handler($prevHandler); }
}

if ($bootstrap_ok) {
  try { use_backend_session(); } catch (Throwable $e) {
    $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: '.$e->getMessage());
  }
}

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* DB */
$db = function_exists('db') ? db() : null;

/* ---------- Helpers (safe probes + settings lookup) ---------- */
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
function nfmt($n, int $dec=0){ return number_format((float)$n, $dec, '.', ','); }

/**
 * Detect tenant_id from Settings (dynamic) with robust fallbacks.
 * Tries common tables/columns:
 *  - settings(key,value) or settings(name,value)
 *  - app_settings(key,value) or app_settings(name,value)
 *  - system_settings(key,value) or system_settings(name,value)
 * Keys tried in priority: tenant_id, current_tenant_id, default_tenant_id
 */
function detect_tenant_id(PDO $db, int $sessionTenantId): int {
  if (!($db instanceof PDO)) return max(0,$sessionTenantId);
  $candidates = ['settings','app_settings','system_settings'];
  $keys = ['tenant_id','current_tenant_id','default_tenant_id'];

  foreach ($candidates as $tbl) {
    if (!t_exists($db,$tbl)) continue;

    $has_key  = c_exists($db,$tbl,'key');
    $has_name = c_exists($db,$tbl,'name');
    $has_val  = c_exists($db,$tbl,'value');

    if ($has_val && ($has_key || $has_name)) {
      $id = null;
      $colKey = $has_key ? 'key' : 'name';
      // Build IN list safely
      $ph = [];
      $bind = [];
      foreach ($keys as $i=>$k){ $p=":k$i"; $ph[]=$p; $bind[$p]=$k; }
      $sql = "SELECT value, {$colKey} FROM {$tbl} WHERE {$colKey} IN (".implode(',', $ph).")";
      try {
        $st=$db->prepare($sql);
        foreach($bind as $p=>$v) $st->bindValue($p,$v,PDO::PARAM_STR);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        // Priority by $keys order
        foreach ($keys as $k) {
          foreach ($rows as $r) {
            $rk = $r[$colKey] ?? '';
            if ($rk === $k) { $id = (int)($r['value'] ?? 0); break 2; }
          }
        }
        if ($id && $id > 0) return $id;
      } catch(Throwable $e){ /* ignore and try next */ }
    }
  }
  // Fallback to session value if present
  return max(0, $sessionTenantId);
}

/* Pull tenant from Settings first; session is fallback */
$sessionTenant = (int)($user['tenant_id'] ?? 0);
$tenantId = ($db instanceof PDO) ? detect_tenant_id($db, $sessionTenant) : $sessionTenant;

/* ---------- Date window: MTD ---------- */
date_default_timezone_set('Africa/Cairo');
$now       = new DateTime('now', new DateTimeZone('Africa/Cairo'));
$fromDate  = (clone $now)->modify('first day of this month')->format('Y-m-d');
$toDate    = $now->format('Y-m-d');
$fromTS    = $fromDate.' 00:00:00';
$toTS      = $toDate.' 23:59:59';

/* ---------- KPIs (defensive, auto-hide if null) ---------- */
$kpis = [
  'new_members'        => null, // int
  'enrolled_share'     => ['enrolled'=>null,'total'=>null,'pct'=>null], // % + counts
  'points_issued'      => null, // float
  'points_redeemed'    => null, // float (abs)
  'cashback_credited'  => null, // float
  'stamps_credited'    => null, // float/int
];

if ($db instanceof PDO && $tenantId > 0) {
  try {
    // New Members (customers.created_at)
    if (t_exists($db,'customers') && c_exists($db,'customers','created_at')) {
      $st=$db->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id=:t AND created_at BETWEEN :f AND :to");
      $st->execute([':t'=>$tenantId, ':f'=>$fromTS, ':to'=>$toTS]);
      $kpis['new_members'] = (int)$st->fetchColumn();
    }

    // Enrolled Share (lifetime)
    if (t_exists($db,'customers') && c_exists($db,'customers','rewards_enrolled')) {
      $st=$db->prepare("SELECT SUM(rewards_enrolled=1) AS enrolled, COUNT(*) AS total FROM customers WHERE tenant_id=:t");
      $st->execute([':t'=>$tenantId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['enrolled'=>0,'total'=>0];
      $en = (int)($row['enrolled'] ?? 0); $tot = (int)($row['total'] ?? 0);
      $kpis['enrolled_share'] = ['enrolled'=>$en,'total'=>$tot,'pct'=> $tot>0 ? round(($en/$tot)*100,2) : null];
    }

    // Points Issued & Redeemed (MTD)
    if (t_exists($db,'loyalty_ledger') && c_exists($db,'loyalty_ledger','points_delta') && c_exists($db,'loyalty_ledger','created_at')) {
      // Issued
      $st=$db->prepare("SELECT COALESCE(SUM(CASE WHEN points_delta>0 THEN points_delta END),0) FROM loyalty_ledger WHERE tenant_id=:t AND created_at BETWEEN :f AND :to");
      $st->execute([':t'=>$tenantId, ':f'=>$fromTS, ':to'=>$toTS]);
      $kpis['points_issued'] = (float)$st->fetchColumn();
      // Redeemed
      $st=$db->prepare("SELECT ABS(COALESCE(SUM(CASE WHEN points_delta<0 THEN points_delta END),0)) FROM loyalty_ledger WHERE tenant_id=:t AND created_at BETWEEN :f AND :to");
      $st->execute([':t'=>$tenantId, ':f'=>$fromTS, ':to'=>$toTS]);
      $kpis['points_redeemed'] = (float)$st->fetchColumn();
    }

    // Cashback credited (MTD) — choose table automatically
    $cashbackTable = null;
    if (t_exists($db,'loyalty_cashback_ledger')) $cashbackTable='loyalty_cashback_ledger';
    elseif (t_exists($db,'loyalty_ledgers'))     $cashbackTable='loyalty_ledgers';
    if ($cashbackTable && c_exists($db,$cashbackTable,'created_at')) {
      $hasDir = c_exists($db,$cashbackTable,'direction');
      $hasAmt = c_exists($db,$cashbackTable,'amount');
      if ($hasAmt) {
        $sql = $hasDir
          ? "SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN amount END),0) FROM {$cashbackTable} WHERE tenant_id=:t AND created_at BETWEEN :f AND :to"
          : "SELECT COALESCE(SUM(CASE WHEN amount>0 THEN amount END),0) FROM {$cashbackTable} WHERE tenant_id=:t AND created_at BETWEEN :f AND :to";
        $st=$db->prepare($sql);
        $st->execute([':t'=>$tenantId, ':f'=>$fromTS, ':to'=>$toTS]);
        $kpis['cashback_credited'] = (float)$st->fetchColumn();
      }
    }

    // Stamps credited (MTD) — choose table automatically
    $stampTable = null;
    if (t_exists($db,'stamp_ledger'))      $stampTable='stamp_ledger';
    elseif (t_exists($db,'stamps_ledger')) $stampTable='stamps_ledger';
    if ($stampTable && c_exists($db,$stampTable,'created_at')) {
      $hasQty = c_exists($db,$stampTable,'qty');
      $hasDir = c_exists($db,$stampTable,'direction');
      if ($hasQty && $hasDir) {
        $sql = "SELECT COALESCE(SUM(CASE WHEN direction='credit' THEN ABS(qty) ELSE 0 END),0) FROM {$stampTable} WHERE tenant_id=:t AND created_at BETWEEN :f AND :to";
      } elseif ($hasQty) {
        $sql = "SELECT COALESCE(SUM(GREATEST(qty,0)),0) FROM {$stampTable} WHERE tenant_id=:t AND created_at BETWEEN :f AND :to";
      } else {
        $sql = "SELECT COUNT(*) FROM {$stampTable} WHERE tenant_id=:t AND created_at BETWEEN :f AND :to";
      }
      $st=$db->prepare($sql);
      $st->execute([':t'=>$tenantId, ':f'=>$fromTS, ':to'=>$toTS]);
      $kpis['stamps_credited'] = (float)$st->fetchColumn();
    }
  } catch(Throwable $e) { /* hide KPIs on error */ }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Rewards · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb;--hover:#f3f4f6;
  --blue:#2563eb;--amber:#f59e0b;--teal:#10b981;--violet:#7c3aed;--indigo:#4f46e5;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
.container{max-width:1200px;margin:20px auto;padding:0 16px}

/* Buttons */
.btn{display:inline-block;padding:9px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;text-decoration:none;color:var(--text);font-weight:700}
.btn:hover{background:var(--hover)}
.btn.primary{background:var(--primary);color:#fff;border-color:var(--primary)}

/* KPI tiles */
.kpis{display:grid;gap:14px;grid-template-columns:repeat(6,1fr);margin-bottom:14px}
.kpi{
  display:block;text-decoration:none;color:var(--text);
  padding:16px;border:1px solid var(--border);border-radius:14px;background:#fff;
  transition:transform .12s ease,box-shadow .2s ease,border-color .2s ease;
}
.kpi:hover{transform:translateY(-1px);box-shadow:0 12px 30px rgba(0,0,0,.1)}
.kpi .k-title{font-size:12.5px;color:var(--muted);margin-bottom:6px}
.kpi .k-num{font-size:22px;font-weight:900}
.kpi.blue   {border-color:color-mix(in srgb, var(--blue) 30%, var(--border));background:linear-gradient(180deg,#fff,color-mix(in srgb,var(--blue) 6%,#fff))}
.kpi.violet {border-color:color-mix(in srgb, var(--violet) 30%, var(--border));background:linear-gradient(180deg,#fff,color-mix(in srgb,var(--violet) 6%,#fff))}
.kpi.teal   {border-color:color-mix(in srgb, var(--teal) 30%, var(--border));background:linear-gradient(180deg,#fff,color-mix(in srgb,var(--teal) 6%,#fff))}
.kpi.indigo {border-color:color-mix(in srgb, var(--indigo) 30%, var(--border));background:linear-gradient(180deg,#fff,color-mix(in srgb,var(--indigo) 6%,#fff))}
.kpi.amber  {border-color:color-mix(in srgb, var(--amber) 30%, var(--border));background:linear-gradient(180deg,#fff,color-mix(in srgb,var(--amber) 6%,#fff))}
@media (max-width:1200px){.kpis{grid-template-columns:repeat(3,1fr)}}
@media (max-width:680px){.kpis{grid-template-columns:repeat(2,1fr)}}
@media (max-width:480px){.kpis{grid-template-columns:1fr}}

/* Program tiles */
.grid{display:grid;gap:14px}
.grid-5{grid-template-columns:repeat(5,1fr)}
@media (max-width:1200px){.grid-5{grid-template-columns:repeat(3,1fr)}}
@media (max-width:760px){.grid-5{grid-template-columns:repeat(2,1fr)}}
@media (max-width:520px){.grid-5{grid-template-columns:1fr}}

.tile{
  display:block;text-decoration:none;color:var(--text);
  border:1px solid var(--border);border-radius:14px;padding:18px 16px;background:#fff;
  transition:transform .12s ease,box-shadow .2s ease,border-color .2s ease;
}
.tile .t-title{font-weight:900;margin-bottom:6px}
.tile .t-desc{color:var(--muted);font-size:13px}
.tile:hover{transform:translateY(-1px);box-shadow:0 12px 30px rgba(0,0,0,.1)}
.tile.points   {border-color:color-mix(in srgb, var(--blue) 30%, var(--border));background:linear-gradient(180deg,#ffffff, color-mix(in srgb, var(--blue) 6%, #fff))}
.tile.stamp    {border-color:color-mix(in srgb, var(--amber) 30%, var(--border));background:linear-gradient(180deg,#ffffff, color-mix(in srgb, var(--amber) 7%, #fff))}
.tile.cashback {border-color:color-mix(in srgb, var(--teal) 30%, var(--border));background:linear-gradient(180deg,#ffffff, color-mix(in srgb, var(--teal) 8%, #fff))}
.tile.common   {border-color:color-mix(in srgb, var(--violet) 30%, var(--border));background:linear-gradient(180deg,#ffffff, color-mix(in srgb, var(--violet) 7%, #fff))}
.tile.reports  {border-color:color-mix(in srgb, var(--indigo) 30%, var(--border));background:linear-gradient(180deg,#ffffff, color-mix(in srgb, var(--indigo) 7%, #fff))}

/* Notices / Debug */
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.debug{background:#eef2ff;border:1px solid #e0e7ff;color:#1e3a8a;padding:10px;border-radius:10px;margin:10px 0;font-size:12px}
</style>
</head>
<body>

<?php
/* Admin nav include (graceful fallback) */
$active='rewards';
$nav_included=false;
$nav1 = __DIR__ . '/../partials/admin_nav.php';
$nav2 = dirname(__DIR__,2) . '/partials/admin_nav.php';
if (is_file($nav1)) { $nav_included=(bool) @include $nav1; }
elseif (is_file($nav2)) { $nav_included=(bool) @include $nav2; }
?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= htmlspecialchars($bootstrap_warning, ENT_QUOTES) ?></div><?php endif; ?>

  <?php if ($DEBUG): ?>
    <div class="debug">
      <strong>Debug</strong><br>
      tenantId detected: <code><?= (int)$tenantId ?></code><br>
      db.php tried:<br>
      <?php foreach ($bootstrap_tried as $p): ?>− <code><?= htmlspecialchars($p, ENT_QUOTES) ?></code><br><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- KPI tiles (MTD) -->
  <?php
    $hasAnyKpi = (
      $kpis['new_members'] !== null ||
      $kpis['enrolled_share']['pct'] !== null ||
      $kpis['points_issued'] !== null ||
      $kpis['points_redeemed'] !== null ||
      $kpis['cashback_credited'] !== null ||
      $kpis['stamps_credited'] !== null
    );
    $qFrom = urlencode($fromDate); $qTo = urlencode($toDate);
  ?>
  <?php if ($hasAnyKpi): ?>
    <div class="kpis" role="region" aria-label="Month-to-date KPIs">
      <?php if ($kpis['new_members'] !== null): ?>
        <a class="kpi blue" href="/views/admin/rewards/common/members.php?mfrom=<?= $qFrom ?>&mto=<?= $qTo ?>">
          <div class="k-title">New Members (MTD)</div>
          <div class="k-num"><?= nfmt($kpis['new_members']) ?></div>
        </a>
      <?php endif; ?>

      <?php if ($kpis['enrolled_share']['pct'] !== null): ?>
        <a class="kpi indigo" href="/views/admin/rewards/common/members.php">
          <div class="k-title">Enrolled Share</div>
          <div class="k-num"><?= nfmt($kpis['enrolled_share']['pct'],2) ?>%</div>
        </a>
      <?php endif; ?>

      <?php if ($kpis['points_issued'] !== null): ?>
        <a class="kpi violet" href="/views/admin/rewards/common/members.php?treward=points&tfrom=<?= $qFrom ?>&tto=<?= $qTo ?>#transactions">
          <div class="k-title">Points Issued (MTD)</div>
          <div class="k-num"><?= nfmt($kpis['points_issued']) ?></div>
        </a>
      <?php endif; ?>

      <?php if ($kpis['points_redeemed'] !== null): ?>
        <a class="kpi violet" href="/views/admin/rewards/common/members.php?treward=points&tfrom=<?= $qFrom ?>&tto=<?= $qTo ?>#transactions">
          <div class="k-title">Points Redeemed (MTD)</div>
          <div class="k-num"><?= nfmt($kpis['points_redeemed']) ?></div>
        </a>
      <?php endif; ?>

      <?php if ($kpis['cashback_credited'] !== null): ?>
        <a class="kpi teal" href="/views/admin/rewards/common/members.php?treward=cashback&tfrom=<?= $qFrom ?>&tto=<?= $qTo ?>#transactions">
          <div class="k-title">Cashback Credited (MTD)</div>
          <div class="k-num"><?= nfmt($kpis['cashback_credited'],2) ?></div>
        </a>
      <?php endif; ?>

      <?php if ($kpis['stamps_credited'] !== null): ?>
        <a class="kpi amber" href="/views/admin/rewards/common/members.php?treward=stamps&tfrom=<?= $qFrom ?>&tto=<?= $qTo ?>#transactions">
          <div class="k-title">Stamps Credited (MTD)</div>
          <div class="k-num"><?= nfmt($kpis['stamps_credited']) ?></div>
        </a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Colorful program tiles (including Reports) -->
  <div class="grid grid-5" style="margin-bottom:14px;">
    <a class="tile points"   href="/views/admin/rewards/points/overview.php"   aria-label="Open Points">
      <div class="t-title">Points</div>
      <div class="t-desc">Earn &amp; redeem rules, catalog &amp; ledgers.</div>
    </a>
    <a class="tile stamp"    href="/views/admin/rewards/stamp/overview.php"    aria-label="Open Stamp">
      <div class="t-title">Stamp</div>
      <div class="t-desc">Digital stamp cards &amp; milestones.</div>
    </a>
    <a class="tile cashback" href="/views/admin/rewards/cashback/overview.php" aria-label="Open Cashback">
      <div class="t-title">Cashback</div>
      <div class="t-desc">Wallet rules, balances &amp; transactions.</div>
    </a>
    <a class="tile common"   href="/views/admin/rewards/common/members.php"    aria-label="Open Common">
      <div class="t-title">Common</div>
      <div class="t-desc">Members, classifications &amp; adjustments.</div>
    </a>
    <a class="tile reports"  href="/views/admin/rewards/reports/overview.php"  aria-label="Open Reports">
      <div class="t-title">Reports</div>
      <div class="t-desc">Overview, performance &amp; exports.</div>
    </a>
  </div>
</div>
</body>
</html>