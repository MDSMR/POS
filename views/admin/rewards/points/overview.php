<?php
// public_html/views/admin/rewards/points/overview.php
// Rewards → Points
// - Order: Loyalty Programs → Customers Balances → Points Transactions → Loyalty Setup
// - Removed KPIs block
// - Programs table adds: Earn rate, Redeem rate, Channels
// - Customers adds: Customer name (best-effort) + Last activity
// - Transactions adds: Type + Order ID (if unavailable shows —)
// - Buttons centered: Create then Cancel (match Cashback)
// - Tier table: fixed header, scroll body only
declare(strict_types=1);

/* Debug */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* Bootstrap db.php (robust resolver) */
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

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }
function normalize_dt(?string $s): ?string {
  if (!$s) return null; $s=trim($s); if ($s==='') return null;
  $s=str_replace('T',' ',$s);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$s)) $s .= ' 00:00:00';
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$s)) $s.=':00';
  if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $s)) return null;
  return $s;
}

/* DB */
$pdo = function_exists('db') ? db() : null;

/* POST: create new version */
$action_msg=''; $action_ok=false;
if ($pdo instanceof PDO && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $act = $_POST['action'] ?? '';
  try{
    if ($act === 'create_version') {
      $name        = trim((string)($_POST['new_name'] ?? ''));
      $status      = (($_POST['new_status'] ?? 'active') === 'inactive') ? 'inactive' : 'active';
      $start_at_in = normalize_dt($_POST['new_start_at'] ?? null);
      $end_at_in   = normalize_dt($_POST['new_end_at'] ?? null);
      $award_timing= in_array(($_POST['new_award_timing'] ?? 'on_payment'), ['on_payment','on_close'], true) ? $_POST['new_award_timing'] : 'on_payment';
      $earn_rate   = number_format((float)($_POST['new_earn_rate'] ?? 10.00), 2, '.', '');
      $redeem_rate = number_format((float)($_POST['new_redeem_rate'] ?? 100.00), 2, '.', '');
      $min_redeem_points  = ($_POST['new_min_redeem_points'] === '' ? null : (int)$_POST['new_min_redeem_points']);
      $max_redeem_percent = ($_POST['new_max_redeem_percent'] === '' ? null : number_format((float)$_POST['new_max_redeem_percent'], 2, '.', ''));
      $rounding    = in_array(($_POST['new_rounding'] ?? 'floor'), ['floor','nearest','ceil'], true) ? $_POST['new_rounding'] : 'floor';

      // Dynamic tiers (free-text names)
      $tiers_names = isset($_POST['tiers_name']) && is_array($_POST['tiers_name']) ? array_values($_POST['tiers_name']) : [];
      $tiers_mults = isset($_POST['tiers_mult']) && is_array($_POST['tiers_mult']) ? array_values($_POST['tiers_mult']) : [];
      $tier_multiplier = [];
      for ($i=0; $i<count($tiers_names); $i++){
        $n = trim((string)$tiers_names[$i]);
        $m = (float)$tiers_mults[$i];
        if ($n !== '' && $m > 0) { $tier_multiplier[$n] = $m; }
      }
      if (!$tier_multiplier) {
        $tier_multiplier = ['Bronze'=>1.00,'Silver'=>1.10,'Gold'=>1.25,'Platinum'=>1.50];
      }

      // Defaults not exposed in form
      $expiry_policy = 'bucket_days';
      $expiry_days   = 365;

      // Channels & exclusions & notes
      $channels_in = $_POST['channels'] ?? ['pos','online'];
      if (!is_array($channels_in)) $channels_in = ['pos','online'];
      $channels_in = array_values(array_intersect(array_map('strval',$channels_in), ['pos','online']));
      if (!$channels_in) $channels_in = ['pos','online'];
      $exclude_aggregators = isset($_POST['excl_aggregators']) && $_POST['excl_aggregators'] === '1';
      $exclude_discounted  = isset($_POST['excl_discounted'])  && $_POST['excl_discounted']  === '1';
      $desc = trim((string)($_POST['desc'] ?? ''));

      $earn_rule = [
        'basis'=>'subtotal_excl_tax_service',
        'eligible_branches'=>'all',
        'eligible_channels'=>$channels_in,
        'exclude_aggregators'=>$exclude_aggregators,
        'exclude_discounted_orders'=>$exclude_discounted,
        'tier_multiplier'=>$tier_multiplier,
      ];
      if ($desc !== '') $earn_rule['description'] = $desc;
      $redeem_rule = new stdClass();

      $pdo->beginTransaction();
      try {
        if ($start_at_in) {
          $upd = $pdo->prepare("UPDATE loyalty_programs
                                SET end_at = DATE_SUB(:s, INTERVAL 1 SECOND)
                                WHERE tenant_id=:t AND program_type='points' AND status='active'
                                  AND (end_at IS NULL OR end_at > :s)");
          $upd->execute([':t'=>$tenantId, ':s'=>$start_at_in]);
        }

        $ins = $pdo->prepare("INSERT INTO loyalty_programs
          (tenant_id, program_type, name, status,
           start_at, end_at,
           earn_mode, earn_rate, redeem_rate,
           min_redeem_points, max_redeem_percent,
           award_timing, expiry_policy, expiry_days, rounding,
           earn_rule_json, redeem_rule_json, created_at, updated_at)
          VALUES
          (:t, 'points', :name, :status,
           :start_at, :end_at,
           'per_currency', :earn_rate, :redeem_rate,
           :minp, :maxp,
           :award, :expol, :exdays, :rounding,
           :erj, :rrj, NOW(), NOW())");
        $ins->execute([
          ':t'=>$tenantId, ':name'=>$name, ':status'=>$status,
          ':start_at'=>$start_at_in, ':end_at'=>$end_at_in,
          ':earn_rate'=>$earn_rate, ':redeem_rate'=>$redeem_rate,
          ':minp'=>$min_redeem_points, ':maxp'=>$max_redeem_percent,
          ':award'=>$award_timing, ':expol'=>$expiry_policy, ':exdays'=>$expiry_days, ':rounding'=>$rounding,
          ':erj'=>json_encode($earn_rule, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
          ':rrj'=>json_encode($redeem_rule, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
        ]);
        $pdo->commit(); $action_ok=true; $action_msg='New version created.';
      } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    }
  } catch (Throwable $e) { $action_ok=false; $action_msg='Action error: '.$e->getMessage(); }
}

/* Filters (auto-apply) */
$tx_period = $_GET['tx_period'] ?? '30';
$prog_tab  = $_GET['tab'] ?? 'live';
$member_q  = trim((string)($_GET['member_q'] ?? ''));

/* Data */
$programs=[]; $live=null; $now=new DateTimeImmutable('now');
if ($pdo instanceof PDO) {
  try {
    // include earn_rule_json for Channels badges
    $st=$pdo->prepare("SELECT id,name,status,program_type,start_at,end_at,created_at,updated_at,
                              earn_mode,earn_rate,redeem_rate,min_redeem_points,max_redeem_percent,
                              award_timing,expiry_policy,expiry_days,rounding,earn_rule_json
                        FROM loyalty_programs
                        WHERE tenant_id=:t AND program_type='points'
                        ORDER BY (CASE WHEN start_at IS NULL THEN 0 ELSE 1 END),
                                 COALESCE(start_at,created_at) DESC, id DESC");
    $st->execute([':t'=>$tenantId]); $programs=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch(Throwable $e){ $bootstrap_warning = $bootstrap_warning ?: ('DB list error: '.$e->getMessage()); }
}
function classify_program(array $r, DateTimeImmutable $now): string {
  $isActive = ($r['status'] ?? '') === 'active';
  $hasStart=!empty($r['start_at']); $hasEnd=!empty($r['end_at']);
  $startOk= !$hasStart || (new DateTimeImmutable($r['start_at']) <= $now);
  $endOk  = !$hasEnd   || (new DateTimeImmutable($r['end_at'])   >= $now);
  if ($isActive && $startOk && $endOk) return 'live';
  if ($hasStart && (new DateTimeImmutable($r['start_at']) > $now)) return 'scheduled';
  return 'past';
}
foreach($programs as $row){ if (classify_program($row,$now)==='live' && $live===null) $live=$row; }

/* Transactions (period) with derived Type + Order (if exists) */
$recent=[]; $days=30;
if ($tx_period==='7') $days=7; elseif ($tx_period==='90') $days=90; elseif ($tx_period==='all') $days=0;
if ($pdo instanceof PDO) {
  try{
    // Keep base columns; we'll derive type and show order if column exists later
    if ($days>0) {
      $q=$pdo->prepare("SELECT id,member_id,points_delta,note,created_at
                        FROM loyalty_ledger
                        WHERE tenant_id=:t AND created_at >= DATE_SUB(NOW(), INTERVAL {$days} DAY)");
      $q->execute([':t'=>$tenantId]);
    } else {
      $q=$pdo->prepare("SELECT id,member_id,points_delta,note,created_at
                        FROM loyalty_ledger
                        WHERE tenant_id=:t");
      $q->execute([':t'=>$tenantId]);
    }
    $recent=$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }catch(Throwable $e){}
}

/* Customers balances (mobile as ID) + last activity + best-effort name */
$members=[];
if ($pdo instanceof PDO) {
  try{
    if ($member_q !== '' && preg_match('/^\d+$/', $member_q)) {
      $st=$pdo->prepare("SELECT ll.member_id,
                                COALESCE(SUM(ll.points_delta),0) AS points_balance,
                                MAX(ll.created_at) AS last_activity
                         FROM loyalty_ledger ll
                         WHERE ll.tenant_id=:t AND ll.member_id=:m
                         GROUP BY ll.member_id
                         ORDER BY points_balance DESC LIMIT 500");
      $st->execute([':t'=>$tenantId, ':m'=>$member_q]);
    } else {
      $st=$pdo->prepare("SELECT ll.member_id,
                                COALESCE(SUM(ll.points_delta),0) AS points_balance,
                                MAX(ll.created_at) AS last_activity
                         FROM loyalty_ledger ll
                         WHERE ll.tenant_id=:t
                         GROUP BY ll.member_id
                         ORDER BY points_balance DESC LIMIT 500");
      $st->execute([':t'=>$tenantId]);
    }
    $members=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }catch(Throwable $e){}
}

/* Try to fetch names by matching customers.phone = member_id (if table exists) */
$namesByMobile=[];
if ($pdo instanceof PDO && $members){
  try{
    $mobiles = array_values(array_unique(array_map(fn($r)=> (string)$r['member_id'], $members)));
    if ($mobiles){
      // build IN list safely
      $in = implode(',', array_fill(0, count($mobiles), '?'));
      $sql = "SELECT phone,name FROM customers WHERE tenant_id=? AND phone IN ($in)";
      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$tenantId], $mobiles));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row){
        $namesByMobile[(string)$row['phone']] = (string)$row['name'];
      }
    }
  }catch(Throwable $e){}
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Points — Overview & Setup · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg:#f7f8fa;--panel:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb;--hover:#f3f4f6;
  --good:#16a34a;--bad:#dc2626;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.5 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial;color:var(--text)}
.container{max-width:1150px;margin:18px auto;padding:0 16px}

/* Card style aligned with Cashback */
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);margin-top:14px}
.card .hd{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card .bd{padding:12px 14px}

.h1{font-size:18px;font-weight:800;margin:0 0 6px}
.sub{color:var(--muted);font-size:13px;margin:0}
.controls{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
input,select,button,textarea{padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font:inherit}
button.btn,a.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border:1px solid var(--border);border-radius:10px;background:#fff;text-decoration:none;color:#111827;cursor:pointer;font-weight:700}
.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn:hover{background:var(--hover)}.btn.primary:hover{filter:brightness(.98)}
.btn.small{padding:6px 10px;font-size:12.5px}

.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin-bottom:12px}
.alert-ok{background:#d1fae5;border:1px solid #a7f3d0;color:#065f46}

/* Tables */
.table{width:100%;border-collapse:separate;border-spacing:0 8px}
.table thead th{color:var(--muted);font-weight:600;text-align:left;padding:8px 10px}
.table tbody tr{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
.table tbody td{padding:10px 12px;vertical-align:middle}

/* Scroll bodies */
.scroll-body{max-height:520px;overflow:auto;border-top:1px solid var(--border)}
.rows-15{max-height:630px}
.rows-10{max-height:420px}

/* Helpers */
.helper,.hint{font-size:13px;color:var(--muted)}
.pts-plus{color:var(--good);font-weight:700}.pts-minus{color:var(--bad);font-weight:700}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);font-size:12px;margin-right:4px}

/* ---------- Loyalty Setup ---------- */
.newprog{padding:0}
.np-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border)}
.np-title{margin:0;font-weight:800}
.np-grid{display:grid;grid-template-columns:1fr 1fr;gap:0}
.np-col{padding:16px}
.np-col+.np-col{border-left:1px solid var(--border)}

/* Stacked field + paired rows */
.stack{display:flex;flex-direction:column;margin-bottom:12px}
.stack label{font-weight:700;margin-bottom:6px}
.stack .hint{margin-top:6px}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:16px}

/* widths */
.w-100{width:100%}.w-lg{min-width:360px;width:100%}.w-md{width:220px}.w-sm{width:160px}.w-xs{width:110px}
select.slim{max-width:160px}

/* Tier multipliers - fixed header, scroll body only */
.tier-wrap{margin-top:6px}
.tier-head table{width:100%;border-collapse:separate;border-spacing:0}
.tier-head th{background:#f9fafb;border:1px solid var(--border);padding:8px 10px;text-align:left}
.tier-body{max-height:220px;overflow:auto;border:1px solid var(--border);border-top:none;border-radius:0 0 10px 10px}
.tier-body table{width:100%;border-collapse:separate;border-spacing:0}
.tier-body td{background:#fff;border-bottom:1px solid var(--border);padding:8px 10px}
.tier-body tr:last-child td{border-bottom:none}
.tier-actions{display:flex;gap:8px;align-items:center}

/* footer actions centered (Create then Cancel) */
.np-foot{padding:12px 16px;border-top:1px solid var(--border);display:flex;gap:10px;justify-content:center;background:linear-gradient(180deg,#fff,#fafafa)}
.np-foot .btn{min-width:140px;justify-content:center}

/* Example Setup block */
.example-setup{margin-top:10px;color:var(--muted);font-size:13px;line-height:1.45;text-align:center}
</style>
</head>
<body>

<?php
/* Safe nav include */
$active='rewards_points';
$nav_included=false;
$nav1=__DIR__ . '/../../partials/admin_nav.php';
$nav2=dirname(__DIR__,3) . '/partials/admin_nav.php';
if (is_file($nav1)) { $nav_included=(bool) @include $nav1; }
elseif (is_file($nav2)) { $nav_included=(bool) @include $nav2; }
?>

<div class="container">
  <div class="h1">Points Rewards</div>
  <p class="sub" style="margin-bottom:10px;">Manage points programs, customer balances, transactions, and configure earning &amp; redemption rules.</p>

  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>
  <?php if ($action_msg): ?><div class="notice <?= $action_ok?'alert-ok':'' ?>"><?= h($action_msg) ?></div><?php endif; ?>

  <!-- Loyalty Programs (FIRST) -->
  <section class="card table-wrap" id="programs" style="margin-top:14px;">
    <div class="hd">
      <strong>Loyalty Programs</strong>
      <div class="controls">
        <label class="helper" for="prog_view">View</label>
        <select id="prog_view">
          <?php foreach(['live'=>'Live','scheduled'=>'Scheduled','past'=>'Past'] as $k=>$label): ?>
            <option value="<?= h($k) ?>" <?= $prog_tab===$k?'selected':'' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="bd">
      <table class="table compact" aria-label="Loyalty Programs">
        <thead>
          <tr>
            <th style="width:64px;">Live</th>
            <th style="width:50px;">#</th>
            <th>Name</th>
            <th style="width:110px;">Earn rate</th>
            <th style="width:120px;">Redeem rate</th>
            <th style="width:160px;">Channels</th>
            <th style="width:200px;">Window</th>
            <th style="width:260px;">Actions</th>
          </tr>
        </thead>
      </table>
      <div class="scroll-body rows-10">
        <table class="table compact">
          <tbody>
            <?php
            $any=false;
            foreach($programs as $r){
              $cls = classify_program($r,$now);
              if ($cls !== $prog_tab) continue;
              $any=true;

              $isLive = ($cls==='live');
              $liveBadge = $isLive
                ? '<span class="badge">Live</span>'
                : '<span class="badge">—</span>';

              $ended = (!empty($r['end_at']) && (new DateTimeImmutable($r['end_at']) < $now));
              $statusText = $ended ? 'Ended' : (($r['status']==='active') ? 'Active' : 'Inactive');

              $win = (empty($r['start_at'])?'—':h($r['start_at'])) . ' → ' . (empty($r['end_at'])?'—':h($r['end_at']));

              $earnRate   = isset($r['earn_rate']) ? number_format((float)$r['earn_rate'],2,'.','') : '—';
              $redeemRate = isset($r['redeem_rate']) ? number_format((float)$r['redeem_rate'],2,'.','') : '—';

              $channels = '—';
              if (!empty($r['earn_rule_json'])) {
                $er = json_decode((string)$r['earn_rule_json'], true);
                if (is_array($er) && !empty($er['eligible_channels']) && is_array($er['eligible_channels'])) {
                  $badges = [];
                  foreach ($er['eligible_channels'] as $ch) {
                    $label = $ch==='pos' ? 'POS' : ($ch==='online' ? 'Online' : $ch);
                    $badges[] = '<span class="badge">'.$label.'</span>';
                  }
                  if ($badges) $channels = implode(' ', $badges);
                }
              }

              echo '<tr data-prog=\''.h(json_encode($r, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)).'\' data-prog-id="'.h($r['id']).'">';
              echo '<td>'.$liveBadge.'</td>';
              echo '<td>'.h($r['id']).'</td>';
              echo '<td><div style="font-weight:800">'.h($r['name']).'</div><div class="sub">'.h($statusText).'</div></td>';
              echo '<td>'.h($earnRate).'</td>';
              echo '<td>'.h($redeemRate).'</td>';
              echo '<td>'.$channels.'</td>';
              echo '<td>'.$win.'</td>';
              echo '<td style="text-align:left;">'
                  . '<button class="btn small js-edit" type="button" title="Edit this program">Edit</button> '
                  . '<button class="btn small js-duplicate" type="button" title="Duplicate this program">Duplicate</button> '
                  . '<button class="btn small js-delete" type="button" title="Delete or archive this program">Delete</button>'
                  . '</td>';
              echo '</tr>';
            }
            if (!$any) echo '<tr><td colspan="8" class="sub" style="padding:12px">Nothing here.</td></tr>';
            ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Customers Balances (SECOND) -->
  <section class="card table-wrap" id="members">
    <div class="hd">
      <strong>Customers Balances</strong>
      <div class="controls" style="gap:6px;">
        <input type="text" id="member_q" placeholder="Type mobile number…" value="<?= h($member_q) ?>" inputmode="numeric">
        <a class="btn small" id="member_clear" href="#" title="Clear search">Clear</a>
      </div>
    </div>
    <div class="bd">
      <table class="table" aria-label="Customers">
        <thead>
          <tr>
            <th style="width:220px;">Customer</th>
            <th style="width:160px;">Mobile</th>
            <th style="width:110px;">Points</th>
            <th style="width:180px;">Last activity</th>
            <th style="text-align:left;width:300px;">Actions</th>
          </tr>
        </thead>
      </table>
      <div class="scroll-body rows-15">
        <table class="table">
          <tbody>
            <?php if (!$members): ?>
              <tr><td colspan="5" class="sub" style="padding:12px">No customers found.</td></tr>
            <?php else: foreach ($members as $m):
              $mobile = (string)$m['member_id'];
              $name = $namesByMobile[$mobile] ?? '';
              $last = $m['last_activity'] ?? '';
            ?>
              <tr>
                <td style="width:220px;"><div style="font-weight:800"><?= h($name ?: '—') ?></div></td>
                <td style="width:160px;"><?= h($mobile) ?></td>
                <td style="width:110px;"><?= h((string)$m['points_balance']) ?> pts</td>
                <td style="width:180px;"><?= h($last ?: '—') ?></td>
                <td style="text-align:left;width:300px;">
                  <a class="btn small" href="/views/admin/rewards/points/ledgers.php?member=<?= h($mobile) ?>">Ledger</a>
                  <a class="btn small" href="/views/admin/rewards/points/adjustments.php?member=<?= h($mobile) ?>">Adjust</a>
                  <a class="btn small" href="/views/admin/rewards/points/redemptions.php?member=<?= h($mobile) ?>">Redeem</a>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Points Transactions (THIRD) -->
  <section class="card table-wrap" id="transactions">
    <div class="hd">
      <strong>Points Transactions</strong>
      <div class="controls" style="gap:6px;">
        <label class="helper" for="tx_period">Period</label>
        <select id="tx_period" name="tx_period">
          <?php foreach(['7'=>'7 days','30'=>'30 days','90'=>'90 days','all'=>'All'] as $k=>$label): ?>
            <option value="<?= h($k) ?>" <?= $tx_period===$k?'selected':'' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="bd">
      <table class="table" aria-label="Transactions">
        <thead>
          <tr>
            <th style="width:80px;">#</th>
            <th style="width:160px;">Customer (mobile)</th>
            <th style="width:100px;">Type</th>
            <th style="width:120px;">Change</th>
            <th>Note</th>
            <th style="width:120px;">Order ID</th>
            <th style="width:180px;">When</th>
          </tr>
        </thead>
      </table>
      <div class="scroll-body rows-15">
        <table class="table">
          <tbody>
            <?php if (!$recent): ?>
              <tr><td colspan="7" class="sub" style="padding:12px">No activity found.</td></tr>
            <?php else: foreach ($recent as $r):
              $pd=(float)($r['points_delta']??0);
              $type = ($pd>0 ? 'Earn' : ($pd<0 ? 'Redeem' : 'Adjust'));
              // best-effort order id from note if like "Order #123"
              $order='—';
              if (!empty($r['note']) && preg_match('/(?:order|#)\s*#?(\d{2,})/i', (string)$r['note'], $m)) $order = '#'.$m[1];
            ?>
              <tr>
                <td style="width:80px;"><?= h($r['id']) ?></td>
                <td style="width:160px;"><?= h($r['member_id']) ?></td>
                <td style="width:100px;"><?= h($type) ?></td>
                <td style="width:120px;" class="<?= $pd>=0?'pts-plus':'pts-minus' ?>"><?= ($pd>=0?'+':'') . h((string)$pd) ?> pts</td>
                <td><?= h($r['note'] ?? '') ?></td>
                <td style="width:120px;"><?= h($order) ?></td>
                <td style="width:180px;"><?= h($r['created_at']) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>

  <!-- Loyalty Setup (LAST) -->
  <section class="card newprog" id="new-program" style="margin-top:14px;">
    <div class="np-head">
      <h2 class="np-title">Loyalty Setup</h2>
    </div>

    <form method="post" id="form-new">
      <input type="hidden" name="action" value="create_version">
      <input type="hidden" id="new_start_at" name="new_start_at">
      <input type="hidden" id="new_end_at" name="new_end_at">

      <div class="np-grid">
        <!-- LEFT column -->
        <div class="np-col">
          <!-- Name -->
          <div class="stack">
            <label for="nv_name">Name</label>
            <input id="nv_name" name="new_name" class="w-lg" placeholder="Program name (e.g., Summer Points 2025)" value="" required>
          </div>

          <!-- Goes live | Ends -->
          <div class="row2">
            <div class="stack">
              <label for="start_dmy">Goes live Date</label>
              <input id="start_dmy" type="date" class="w-md">
              <div class="hint">Start date of the program.</div>
            </div>
            <div class="stack">
              <label for="end_dmy">Ends Date</label>
              <input id="end_dmy" type="date" class="w-md">
              <div class="hint">Leave empty for ongoing.</div>
            </div>
          </div>

          <!-- Award timing | Rounding -->
          <div class="row2">
            <div class="stack">
              <label for="nv_award">Award timing</label>
              <?php $aw = $live['award_timing'] ?? 'on_payment'; ?>
              <select id="nv_award" name="new_award_timing" class="w-sm slim">
                <option value="on_payment" <?= $aw==='on_payment'?'selected':''; ?>>On payment</option>
                <option value="on_close"   <?= $aw==='on_close'?'selected':''; ?>>On close</option>
              </select>
              <div class="hint">When points are awarded.</div>
            </div>
            <div class="stack">
              <label for="nv_rounding">Rounding</label>
              <select id="nv_rounding" name="new_rounding" class="w-sm slim">
                <option value="floor" selected>Floor</option>
                <option value="nearest">Nearest</option>
                <option value="ceil">Ceil</option>
              </select>
            </div>
          </div>

          <!-- Sales channels | Exclusions -->
          <div class="row2">
            <div class="stack">
              <label>Sales channels</label>
              <div>
                <label class="helper" style="margin-right:8px;"><input type="checkbox" name="channels[]" value="pos" checked> POS</label>
                <label class="helper"><input type="checkbox" name="channels[]" value="online" checked> Online</label>
              </div>
            </div>
            <div class="stack">
              <label>Exclusions</label>
              <div>
                <label class="helper" style="margin-right:8px;"><input type="checkbox" name="excl_aggregators" value="1"> Aggregator</label>
                <label class="helper"><input type="checkbox" name="excl_discounted" value="1"> Orders</label>
              </div>
            </div>
          </div>

          <!-- Notes | Status -->
          <div class="row2">
            <div class="stack">
              <label for="nv_notes">Notes</label>
              <textarea id="nv_notes" name="desc" class="w-lg" rows="2" placeholder="Optional notes (internal)"></textarea>
            </div>
            <div class="stack">
              <label for="nv_status">Status</label>
              <select id="nv_status" name="new_status" class="w-sm slim">
                <option value="active" selected>Active</option>
                <option value="inactive">Inactive</option>
              </select>
            </div>
          </div>
        </div>

        <!-- RIGHT column -->
        <div class="np-col">
          <!-- Tier multipliers (fixed head + scroll body) -->
          <div class="stack">
            <label>Tier multipliers</label>
            <div class="tier-wrap">
              <div class="tier-head">
                <table aria-hidden="true">
                  <thead>
                    <tr>
                      <th style="width:55%;">Tier name</th>
                      <th style="width:25%;">Multiplier</th>
                      <th style="width:20%;">Action</th>
                    </tr>
                  </thead>
                </table>
              </div>
              <div class="tier-body">
                <table aria-label="Tier multipliers">
                  <tbody id="tierTbody">
                    <tr>
                      <td style="width:55%;"><input type="text" name="tiers_name[]" value="Bronze" class="w-100" required></td>
                      <td style="width:25%;"><input type="number" name="tiers_mult[]" value="1.00" step="0.01" min="0.01" class="w-sm" required></td>
                      <td style="width:20%;"><button class="btn small js-tier-del" type="button" aria-label="Remove tier">Remove</button></td>
                    </tr>
                    <tr>
                      <td><input type="text" name="tiers_name[]" value="Silver" class="w-100" required></td>
                      <td><input type="number" name="tiers_mult[]" value="1.10" step="0.01" min="0.01" class="w-sm" required></td>
                      <td><button class="btn small js-tier-del" type="button">Remove</button></td>
                    </tr>
                    <tr>
                      <td><input type="text" name="tiers_name[]" value="Gold" class="w-100" required></td>
                      <td><input type="number" name="tiers_mult[]" value="1.25" step="0.01" min="0.01" class="w-sm" required></td>
                      <td><button class="btn small js-tier-del" type="button">Remove</button></td>
                    </tr>
                    <tr>
                      <td><input type="text" name="tiers_name[]" value="Platinum" class="w-100" required></td>
                      <td><input type="number" name="tiers_mult[]" value="1.50" step="0.01" min="0.01" class="w-sm" required></td>
                      <td><button class="btn small js-tier-del" type="button">Remove</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="tier-actions" style="margin-top:8px;">
                <button class="btn small" type="button" id="js-tier-add">Add tier</button>
                <span class="helper">Max 8 tiers.</span>
              </div>
            </div>
          </div>

          <!-- Earning / Redemption (below tiers) -->
          <div class="row2">
            <?php
              $earnDefault = isset($live['earn_rate']) ? number_format((float)$live['earn_rate'], 2, '.', '') : '10.00';
              $redeemDefault = isset($live['redeem_rate']) ? number_format((float)$live['redeem_rate'], 2, '.', '') : '100.00';
            ?>
            <div class="stack">
              <label for="nv_earn">Earning rate</label>
              <input id="nv_earn" class="w-xs" type="number" step="0.01" min="0.01" name="new_earn_rate" value="<?= h($earnDefault) ?>" required>
              <div class="hint">Points per 1 currency. Example: 1 EGP → 1 point.</div>
            </div>
            <div class="stack">
              <label for="nv_redeem">Redemption rate</label>
              <input id="nv_redeem" class="w-xs" type="number" step="0.01" min="0.01" name="new_redeem_rate" value="<?= h($redeemDefault) ?>" required>
              <div class="hint">Value per point. Example: 1 point = 0.10 EGP.</div>
            </div>
          </div>

          <!-- Min / Max -->
          <div class="row2">
            <div class="stack">
              <label for="nv_minp">Min redeem points</label>
              <input id="nv_minp" class="w-xs" type="number" min="0" name="new_min_redeem_points" value="">
              <div class="hint">Minimum points to start redeeming (e.g., 50).</div>
            </div>
            <div class="stack">
              <label for="nv_maxp">Max redeem %</label>
              <input id="nv_maxp" class="w-xs" type="number" step="0.01" min="0" max="100" name="new_max_redeem_percent" value="">
              <div class="hint">Max % of bill payable with points (e.g., 20%).</div>
            </div>
          </div>
        </div>
      </div>

      <div class="np-foot">
        <button class="btn primary" type="submit">Create</button>
        <button class="btn" type="button" onclick="document.getElementById('programs').scrollIntoView({behavior:'smooth'});">Cancel</button>
      </div>

      <div class="example-setup">
        <strong>Example Setup:</strong>
        Earn <em>1 point per 1 EGP</em>. Redeem after <em>50 points</em>, up to <em>20% of a bill</em>.
        Gold tier (1.5×): spend 100 EGP → <em>150 points</em>.
      </div>
    </form>
  </section>

  <?php if ($DEBUG): ?>
    <div class="card" style="margin-top:14px;">
      <div class="h1">Debug</div>
      <div class="bd"><pre style="white-space:pre-wrap;margin:0;"><?php
        foreach ($bootstrap_tried as $p) echo " - $p\n";
      ?></pre>
      <div class="sub">Resolved: <?= h($bootstrap_found ?: 'NOT FOUND') ?></div></div>
    </div>
  <?php endif; ?>
</div>

<script>
(function(){
  function updateQuery(params, hash){
    const u = new URL(window.location.href);
    Object.keys(params).forEach(k=>{
      if (params[k]===null || params[k]==='') u.searchParams.delete(k);
      else u.searchParams.set(k, params[k]);
    });
    if (hash) u.hash = '#'+hash;
    window.location.replace(u.toString());
  }

  // Auto filters
  const txSel = document.getElementById('tx_period');
  txSel?.addEventListener('change', ()=>{ updateQuery({tx_period: txSel.value}, 'transactions'); });
  const memberInput = document.getElementById('member_q');
  const memberClear = document.getElementById('member_clear');
  let t=null;
  function debounceApply(){ if (t) clearTimeout(t); t=setTimeout(()=>{ updateQuery({member_q: memberInput.value}, 'members'); }, 350); }
  memberInput?.addEventListener('input', debounceApply);
  memberClear?.addEventListener('click', (e)=>{ e.preventDefault(); memberInput.value=''; updateQuery({member_q:''}, 'members'); });
  const progView = document.getElementById('prog_view');
  progView?.addEventListener('change', ()=>{ updateQuery({tab: progView.value}, 'programs'); });

  // Date pickers → hidden normalized values
  const startPicker = document.getElementById('start_dmy');
  const endPicker   = document.getElementById('end_dmy');
  function todayYMD(){
    const d=new Date();
    const y=d.getFullYear();
    const m=String(d.getMonth()+1).padStart(2,'0');
    const day=String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${day}`;
  }
  function normalizeFromDateInput(el){
    const v = (el?.value || '').trim();
    if (!v || !/^\d{4}-\d{2}-\d{2}$/.test(v)) return '';
    return v + ' 00:00:00';
  }
  if (startPicker && !startPicker.value) startPicker.value = todayYMD();
  if (endPicker && !endPicker.value)     endPicker.value   = todayYMD();

  // Prefill helpers (Edit / Duplicate)
  function extractYMD(datetimeString){
    if (!datetimeString) return '';
    const s=String(datetimeString);
    return /^\d{4}-\d{2}-\d{2}/.test(s) ? s.slice(0,10) : '';
  }
  function prefillFromRow(row, mode){
    try{
      const data = JSON.parse(row.getAttribute('data-prog')||'{}');
      const nameEl = document.getElementById('nv_name');
      if (nameEl) nameEl.value = data.name ? (mode==='duplicate' ? (data.name + ' (copy)') : data.name) : '';
      const earn = document.getElementById('nv_earn');
      const redeem = document.getElementById('nv_redeem');
      if (data.earn_rate && earn) earn.value = (parseFloat(data.earn_rate)||0).toFixed(2);
      if (data.redeem_rate && redeem) redeem.value = (parseFloat(data.redeem_rate)||0).toFixed(2);
      if (data.award_timing) document.getElementById('nv_award').value = data.award_timing;

      const s = extractYMD(data.start_at || '');
      const e = extractYMD(data.end_at   || '');
      if (s && startPicker) startPicker.value = s; else if (startPicker && !startPicker.value) startPicker.value = todayYMD();
      if (e && endPicker)   endPicker.value   = e; else if (endPicker && !endPicker.value)     endPicker.value   = todayYMD();

      document.getElementById('nv_status').value = (data.status === 'inactive') ? 'inactive' : 'active';
      document.getElementById('new-program')?.scrollIntoView({behavior:'smooth', block:'start'});
    }catch(_e){}
  }
  document.querySelectorAll('.js-edit').forEach(btn=>{
    btn.addEventListener('click', ()=>{ const row=btn.closest('tr[data-prog]'); if (row) prefillFromRow(row, 'edit'); });
  });
  document.querySelectorAll('.js-duplicate').forEach(btn=>{
    btn.addEventListener('click', ()=>{ const row=btn.closest('tr[data-prog]'); if (row) prefillFromRow(row, 'duplicate'); });
  });

  // Delete / Archive
  async function requestDelete(programId){
    try{
      const res = await fetch('/controllers/admin/rewards/points/program_delete.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'program_id=' + encodeURIComponent(programId)
      });
      if (!res.ok) throw new Error('Server responded ' + res.status);
      const data = await res.json();
      if (data && data.ok) {
        alert(data.mode === 'deleted' ? 'Program deleted.' : 'Program archived.');
        location.reload();
      } else {
        alert(data && data.error ? data.error : 'Delete/Archive failed.');
      }
    } catch (e){
      alert('Delete/Archive error: ' + (e?.message || e));
    }
  }
  document.querySelectorAll('.js-delete').forEach(btn=>{
    btn.addEventListener('click', ()=>{ 
      const row=btn.closest('tr[data-prog]'); if (!row) return;
      const pid=row.getAttribute('data-prog-id');
      const msg = 'If this program has activity, it will be archived (ended and set inactive) instead of permanently deleted.\n\nProceed?';
      if (confirm(msg)) requestDelete(pid);
    });
  });

  // Validation + set hidden dates on submit
  const formNew = document.getElementById('form-new');
  formNew?.addEventListener('submit',(e)=>{
    const earn = document.getElementById('nv_earn');
    const redeem = document.getElementById('nv_redeem');
    let ok=true;
    [earn,redeem].forEach(f=>{
      const v=parseFloat(f.value||'0'); if (!(v>0)){ f.style.borderColor='#dc2626'; ok=false; } else f.style.borderColor='';
    });

    // Tier validation & cap at 8
    const names = Array.from(document.querySelectorAll('input[name="tiers_name[]"]'));
    const mults = Array.from(document.querySelectorAll('input[name="tiers_mult[]"]'));
    if (names.length>8) { alert('Please keep at most 8 tiers.'); e.preventDefault(); return; }
    for (let i=0;i<names.length;i++){
      const n=(names[i].value||'').trim(); const m=parseFloat(mults[i].value||'0');
      if (!n || !(m>0)) { names[i].style.borderColor='#dc2626'; mults[i].style.borderColor='#dc2626'; ok=false; }
      else { names[i].style.borderColor=''; mults[i].style.borderColor=''; }
    }

    document.getElementById('new_start_at').value = normalizeFromDateInput(startPicker);
    document.getElementById('new_end_at').value   = normalizeFromDateInput(endPicker);

    if (!ok) e.preventDefault();
  });

  // Tier add/remove
  const tierTbody = document.getElementById('tierTbody');
  document.getElementById('js-tier-add')?.addEventListener('click', ()=>{
    if (!tierTbody) return;
    if (tierTbody.querySelectorAll('tr').length >= 8) { alert('Maximum 8 tiers.'); return; }
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td style="width:55%;"><input type="text" name="tiers_name[]" class="w-100" required></td>'+
      '<td style="width:25%;"><input type="number" name="tiers_mult[]" value="1.00" step="0.01" min="0.01" class="w-sm" required></td>'+
      '<td style="width:20%;"><button class="btn small js-tier-del" type="button">Remove</button></td>';
    tierTbody.appendChild(tr);
  });
  document.addEventListener('click', (e)=>{
    const el = e.target;
    if (el && el.classList && el.classList.contains('js-tier-del')){
      const tr = el.closest('tr'); if (!tr) return;
      if (tierTbody?.querySelectorAll('tr').length<=1){ alert('Keep at least one tier.'); return; }
      tr.remove();
    }
  });
})();
</script>
</body>
</html>