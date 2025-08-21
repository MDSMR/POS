<?php
// public_html/views/admin/rewards/cashback/overview.php
declare(strict_types=1);

/* Debug flag */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* Bootstrap db + session (resolve /config/db.php) */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_tried=[]; $bootstrap_path=__DIR__ . '/../../../../config/db.php'; $bootstrap_tried[]=$bootstrap_path;
if (!is_file($bootstrap_path)) {
  $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  if ($docRoot) { $alt = $docRoot.'/config/db.php'; $bootstrap_tried[]=$alt; if (is_file($alt)) $bootstrap_path=$alt; }
}
if (!is_file($bootstrap_path)) {
  $bootstrap_warning='Configuration file not found: /config/db.php';
} else {
  $prev = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path; // db(), use_backend_session()
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok=true; }
  } catch (Throwable $e) { $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally { if ($prev) set_error_handler($prev); }
}

/* Session + auth */
if ($bootstrap_ok) {
  try { use_backend_session(); } catch (Throwable $e) {
    $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: '.$e->getMessage());
  }
}
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);
$db = function_exists('db') ? db() : null;

/* Helpers */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pagelinks($total, $page, $limit, $qsKey){
  $pages = max(1, (int)ceil($total / $limit));
  if ($pages <= 1) return '';
  $qs = $_GET; unset($qs[$qsKey]);
  $base = strtok($_SERVER['REQUEST_URI'], '?');
  $out = '<div class="pager">';
  if ($page > 1) { $qs[$qsKey]=$page-1; $out.='<a class="btn" href="'.$base.'?'.http_build_query($qs).'">Prev</a>'; }
  $out.='<span class="muted">Page '.$page.' of '.$pages.'</span>';
  if ($page < $pages) { $qs[$qsKey]=$page+1; $out.='<a class="btn" href="'.$base.'?'.http_build_query($qs).'">Next</a>'; }
  return $out.'</div>';
}

/* Paging */
$pagePrograms  = max(1,(int)($_GET['p_programs'] ?? 1));
$pageCustomers = max(1,(int)($_GET['p_customers'] ?? 1));
$limit=12;
$offPrograms  = ($pagePrograms-1)*$limit;
$offCustomers = ($pageCustomers-1)*$limit;
$pageTx        = max(1,(int)($_GET['p_tx'] ?? 1));
$offTx        = ($pageTx-1)*$limit;

/* Selected program */
$todayYmd = (new DateTime('today'))->format('Y-m-d');
$selectedProgramId = (int)($_GET['program_id'] ?? 0);
try{
  if ($db instanceof PDO && $selectedProgramId<=0){
    $s=$db->prepare("SELECT id FROM loyalty_programs
                     WHERE tenant_id=? AND program_type='cashback'
                       AND status='active'
                       AND (start_at IS NULL OR start_at<=?)
                       AND (end_at   IS NULL OR end_at  >=?)
                     ORDER BY id DESC LIMIT 1");
    $s->execute([$tenantId,$todayYmd,$todayYmd]);
    $selectedProgramId=(int)($s->fetchColumn() ?: 0);
    if ($selectedProgramId<=0){
      $s=$db->prepare("SELECT id FROM loyalty_programs
                       WHERE tenant_id=? AND program_type='cashback'
                       ORDER BY id DESC LIMIT 1");
      $s->execute([$tenantId]); $selectedProgramId=(int)($s->fetchColumn() ?: 0);
    }
  }
} catch(Throwable $e){}

/* Programs list */
$programs=[]; $programsTotal=0;
if ($db instanceof PDO){
  try{
    $q=$db->prepare("SELECT SQL_CALC_FOUND_ROWS id,name,status,start_at,end_at,rounding,award_timing,
                            max_redeem_percent,min_redeem_points,earn_rule_json
                     FROM loyalty_programs
                     WHERE tenant_id=:t AND program_type='cashback'
                     ORDER BY id DESC
                     LIMIT :o,:l");
    $q->bindValue(':t',$tenantId,PDO::PARAM_INT);
    $q->bindValue(':o',$offPrograms,PDO::PARAM_INT);
    $q->bindValue(':l',$limit,PDO::PARAM_INT);
    $q->execute();
    $programs=$q->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $programsTotal=(int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();
  }catch(Throwable $e){}
}

/* Customers balances */
$customers=[]; $customersTotal=0;
if ($db instanceof PDO && $selectedProgramId>0){
  try{
    $sql = "SELECT SQL_CALC_FOUND_ROWS c.id, c.name, c.phone,
              COALESCE((
                SELECT SUM(CASE WHEN ll.direction='redeem' THEN -ll.amount ELSE ll.amount END)
                FROM loyalty_ledgers ll
                WHERE ll.tenant_id=:t AND ll.program_type='cashback'
                  AND ll.program_id=:pid AND ll.customer_id=c.id
              ),0.00) AS balance
            FROM customers c
            WHERE c.tenant_id=:t
            ORDER BY c.id DESC
            LIMIT :o,:l";
    $st=$db->prepare($sql);
    $st->bindValue(':t',$tenantId,PDO::PARAM_INT);
    $st->bindValue(':pid',$selectedProgramId,PDO::PARAM_INT);
    $st->bindValue(':o',$offCustomers,PDO::PARAM_INT);
    $st->bindValue(':l',$limit,PDO::PARAM_INT);
    $st->execute();
    $customers=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $customersTotal=(int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();
  }catch(Throwable $e){}
}

/* Transactions list */
$tx=[]; $txTotal=0;
if ($db instanceof PDO && $selectedProgramId>0){
  try{
    $sql="SELECT SQL_CALC_FOUND_ROWS ll.id, ll.created_at, ll.direction, ll.amount, ll.order_id, ll.user_id,
                 c.name AS customer_name, c.id AS customer_id
          FROM loyalty_ledgers ll
          LEFT JOIN customers c ON c.id=ll.customer_id
          WHERE ll.tenant_id=:t AND ll.program_type='cashback' AND ll.program_id=:pid
          ORDER BY ll.id DESC
          LIMIT :o,:l";
    $st=$db->prepare($sql);
    $st->bindValue(':t',$tenantId,PDO::PARAM_INT);
    $st->bindValue(':pid',$selectedProgramId,PDO::PARAM_INT);
    $st->bindValue(':o',$offTx,PDO::PARAM_INT);
    $st->bindValue(':l',$limit,PDO::PARAM_INT);
    $st->execute();
    $tx=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $txTotal=(int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();
  }catch(Throwable $e){}
}

/* Prefill for duplicate */
$dupId = isset($_GET['duplicate']) ? (int)$_GET['duplicate'] : 0;
$dup = null;
if ($db instanceof PDO && $dupId>0){
  try{
    $st=$db->prepare("SELECT * FROM loyalty_programs WHERE id=? AND tenant_id=? AND program_type='cashback'");
    $st->execute([$dupId,$tenantId]); $dup=$st->fetch(PDO::FETCH_ASSOC) ?: null;
  }catch(Throwable $e){}
}

/* Defaults for Program Setup */
$prefName   = $dup ? ((string)$dup['name'].' (Copy)') : 'Program name';
$prefStart  = $dup ? (string)($dup['start_at'] ?? $todayYmd) : $todayYmd;
$prefEnd    = $dup ? (string)($dup['end_at'] ?? '') : '';
$prefStatus = $dup ? (string)($dup['status'] ?? 'active') : 'active';
$prefAward  = $dup ? (string)($dup['award_timing'] ?? 'on_payment') : 'on_payment';
$prefRound  = $dup ? (string)($dup['rounding'] ?? 'floor') : 'floor';
$prefMaxPct = $dup ? (float)($dup['max_redeem_percent'] ?? 0) : 0;
$prefMinAmt = $dup ? (float)($dup['min_redeem_points'] ?? 0)   : 0; // reused as amount
$prefMinVisitRedeem = 2;
$prefWalletExpiry   = 0;
$prefLadder = [];
if ($dup && !empty($dup['earn_rule_json'])) {
  $er = json_decode((string)$dup['earn_rule_json'], true);
  if (is_array($er)) {
    $prefMinVisitRedeem = (int)($er['min_visit_to_redeem'] ?? 2);
    $prefWalletExpiry   = (int)($er['expiry']['days'] ?? 0);
    foreach ((array)($er['ladder'] ?? []) as $row) {
      $prefLadder[] = [
        'visit'      => (string)($row['visit'] ?? ''),
        'rate_pct'   => isset($row['rate']) ? (float)$row['rate']*100 : 0,
        'valid_days' => (int)($row['valid_days'] ?? 0),
      ];
    }
  }
}
if (!$prefLadder) {
  // Default 4 visits
  $prefLadder = [
    ['visit'=>'1',  'rate_pct'=>10.0, 'valid_days'=>14],
    ['visit'=>'2',  'rate_pct'=>15.0, 'valid_days'=>20],
    ['visit'=>'3',  'rate_pct'=>20.0, 'valid_days'=>25],
    ['visit'=>'4+', 'rate_pct'=>25.0, 'valid_days'=>30],
  ];
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cashback · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb;--hover:#f3f4f6;--good:#059669;--warn:#d97706;--bad:#dc2626}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
.container{max-width:1150px;margin:20px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);margin-top:14px}
.card .hd{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.card .bd{padding:12px 14px}
.h1{font-size:18px;font-weight:800;margin:0 0 10px}
.muted{color:var(--muted);font-size:13px}
.row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:700;text-decoration:none;cursor:pointer;color:#111827}
.btn:hover{background:var(--hover)}
.btn.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn.small{padding:6px 9px;font-weight:700;font-size:12.5px}
input[type="text"],input[type="number"],input[type="date"],input[type="search"],select,textarea{
  width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-size:14px
}
/* EXACT look shared by Program name and Visit input */
.input-name{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-size:14px}

table{width:100%;border-collapse:separate;border-spacing:0 8px}
th,td{font-size:14px;padding:10px 12px;vertical-align:middle}
thead th{color:var(--muted);text-align:left}
tbody tr{background:#fff;border:1px solid var(--border);border-radius:10px;overflow:hidden}
tbody td{border-top:1px solid var(--border)}
.right{text-align:right}.left{text-align:left}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid var(--border);font-size:12px;margin-left:6px}
.status.good{color:var(--good)} .status.warn{color:var(--warn)} .status.bad{color:var(--bad)}
.pager{display:flex;gap:8px;align-items:center;margin-top:8px}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}

/* Slide-over */
.panel-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.52);display:none}
.panel{position:fixed;top:0;right:0;height:100%;width:520px;max-width:100%;background:#fff;border-left:1px solid var(--border);transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;z-index:50}
.panel.open + .panel-backdrop{display:block}
.panel.open{transform:translateX(0)}
.panel-hd{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-bd{padding:12px 14px;overflow:auto}
.tabs{display:flex;gap:8px;border-bottom:1px solid var(--border);margin-bottom:10px}
.tab{padding:8px 10px;border:1px solid var(--border);border-bottom:none;border-radius:10px 10px 0 0;background:#f8fafc;cursor:pointer}
.tab.active{background:#fff;font-weight:800}

/* Program Setup grid */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-col{flex:1;min-width:220px}
.btnbar{display:flex;gap:10px;justify-content:center;margin-top:10px}

/* Ladder: fixed header + scroll body */
.ladder-wrap{margin-top:6px}
.ladder-head table{width:100%;border-collapse:separate;border-spacing:0}
.ladder-head th{background:#f9fafb;border:1px solid var(--border);padding:8px 10px;text-align:left}
.ladder-body{max-height:260px;overflow:auto;border:1px solid var(--border);border-top:none;border-radius:0 0 10px 10px}
.ladder-body table{width:100%;border-collapse:separate;border-spacing:0}
.ladder-body td{background:#fff;border-bottom:1px solid var(--border);padding:8px 10px}
.ladder-body tr:last-child td{border-bottom:none}
.helper{font-size:12.5px;color:var(--muted)}
.header-actions{display:flex;gap:10px;align-items:center}

/* Compact inputs for numeric fields */
select.slim{max-width:160px}
input.w-xs{max-width:110px}
input.w-sm{max-width:160px}
</style>
</head>
<body>

<?php
/* Admin nav include */
$active='rewards_cashback';
$nav_included=false;
$nav1 = __DIR__ . '/../../partials/admin_nav.php';
$nav2 = dirname(__DIR__,3) . '/partials/admin_nav.php';
if (is_file($nav1)) { $nav_included=(bool) @include $nav1; }
elseif (is_file($nav2)) { $nav_included=(bool) @include $nav2; }
if (!$nav_included): ?>
  <div class="notice" style="max-width:1150px;margin:10px auto;padding:10px 16px;">
    Navigation header not found.
  </div>
<?php endif; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>

  <div class="h1">Cashback Rewards</div>
  <div class="muted">Define visit‑based cashback, view customer wallets, and review transactions. Auto‑redeem starts from your chosen visit.</div>

  <!-- Cashback Programs -->
  <div class="card" id="programs">
    <div class="hd">
      <strong>Cashback Programs</strong>
      <div class="header-actions">
        <input type="search" id="filter-programs" placeholder="Filter programs..." aria-label="Filter programs" style="min-width:220px">
        <a class="btn small primary" href="#setup">Add</a>
      </div>
    </div>
    <div class="bd">
      <table id="table-programs">
        <thead>
          <tr>
            <th style="width:36%">Program</th>
            <th>Status</th>
            <th>Goes live</th>
            <th>Ends</th>
            <th>Max redeem %</th>
            <th class="left" style="width:280px">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$programs): ?>
            <tr><td colspan="6" style="padding:16px">No cashback programs yet.</td></tr>
          <?php else: foreach ($programs as $p):
            $status = (string)($p['status'] ?? '');
            $statusClass = $status==='active' ? 'good' : ($status==='paused' ? 'warn' : 'bad');
            $maxPct = number_format((float)($p['max_redeem_percent'] ?? 0), 2, '.', '');
          ?>
            <tr>
              <td style="border-top:none">
                <div style="font-weight:800"><?= h($p['name']) ?></div>
                <div class="muted">ID #<?= (int)$p['id'] ?> · Award: <?= h($p['award_timing'] ?: 'on_payment') ?> · Rounding: <?= h($p['rounding'] ?: 'floor') ?></div>
              </td>
              <td class="status <?= $statusClass ?>" style="border-top:none"><?= h($status ?: 'inactive') ?></td>
              <td style="border-top:none"><?= h($p['start_at'] ?: '—') ?></td>
              <td style="border-top:none"><?= h($p['end_at'] ?: '—') ?></td>
              <td style="border-top:none"><?= h($maxPct) ?>%</td>
              <td class="left" style="border-top:none">
                <a class="btn small" href="/views/admin/rewards/cashback/overview.php?program_id=<?= (int)$p['id'] ?>#setup">Open</a>
                <a class="btn small" href="/views/admin/rewards/cashback/overview.php?program_id=<?= (int)$p['id'] ?>&duplicate=<?= (int)$p['id'] ?>#setup">Duplicate</a>
                <button class="btn small js-delete-prog" data-id="<?= (int)$p['id'] ?>" type="button">Delete</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?= pagelinks($programsTotal, $pagePrograms, $limit, 'p_programs'); ?>
    </div>
  </div>

  <!-- Customers Balances -->
  <div class="card" id="customers">
    <div class="hd">
      <strong>Customers Balances</strong>
      <div class="header-actions">
        <input type="search" id="filter-customers" placeholder="Filter customers..." aria-label="Filter customers" style="min-width:240px">
      </div>
    </div>
    <div class="bd">
      <table id="table-customers">
        <thead>
          <tr>
            <th class="left">Customer</th>
            <th class="left" style="width:160px">Mobile</th>
            <th class="right" style="width:120px">Wallet</th>
            <th class="left" style="width:260px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($selectedProgramId <= 0): ?>
            <tr><td colspan="4" style="padding:16px">No program selected. Click “Open” in Cashback Programs to choose one.</td></tr>
          <?php elseif (!$customers): ?>
            <tr><td colspan="4" style="padding:16px">No customers found.</td></tr>
          <?php else: foreach ($customers as $c): ?>
            <tr data-customer="<?= (int)$c['id'] ?>">
              <td style="border-top:none">
                <div style="font-weight:800"><?= h($c['name'] ?: ('ID #'.(int)$c['id'])) ?></div>
                <div class="muted">ID #<?= (int)$c['id'] ?></div>
              </td>
              <td class="left" style="border-top:none"><?= h($c['phone'] ?? '—') ?></td>
              <td class="right" style="border-top:none"><span class="cust-balance"><?= number_format((float)($c['balance'] ?? 0), 2, '.', '') ?></span></td>
              <td class="left" style="border-top:none">
                <button class="btn small js-ledger" data-id="<?= (int)$c['id'] ?>">Ledger</button>
                <button class="btn small js-adjust" data-id="<?= (int)$c['id'] ?>">Adjust</button>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?= pagelinks($customersTotal, $pageCustomers, $limit, 'p_customers'); ?>
    </div>
  </div>

  <!-- Cashback transactions -->
  <div class="card" id="transactions">
    <div class="hd">
      <strong>Cashback transactions</strong>
      <div class="header-actions">
        <input type="search" id="filter-tx" placeholder="Filter transactions..." aria-label="Filter transactions" style="min-width:260px">
      </div>
    </div>
    <div class="bd">
      <table id="table-tx">
        <thead>
          <tr>
            <th>Date/Time</th>
            <th>Customer</th>
            <th>Direction</th>
            <th class="right">Amount</th>
            <th>Order</th>
            <th>User</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($selectedProgramId <= 0): ?>
            <tr><td colspan="6" style="padding:16px">No program selected. Click “Open” in Cashback Programs to choose one.</td></tr>
          <?php elseif (!$tx): ?>
            <tr><td colspan="6" style="padding:16px">No transactions found.</td></tr>
          <?php else: foreach ($tx as $t): ?>
            <tr>
              <td style="border-top:none"><?= h($t['created_at']) ?></td>
              <td style="border-top:none"><?= h(($t['customer_name'] ?: 'ID #'.(int)$t['customer_id'])) ?></td>
              <td style="border-top:none"><?= h($t['direction']) ?></td>
              <td class="right" style="border-top:none"><?= number_format((float)$t['amount'], 2, '.', '') ?></td>
              <td style="border-top:none"><?= $t['order_id'] ? ('#'.(int)$t['order_id']) : '—' ?></td>
              <td style="border-top:none"><?= $t['user_id'] ? ('#'.(int)$t['user_id']) : '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?= pagelinks($txTotal, $pageTx, $limit, 'p_tx'); ?>
    </div>
  </div>

  <!-- Program Setup -->
  <div class="card" id="setup">
    <div class="hd"><strong>Program Setup</strong></div>
    <div class="bd">
      <form id="programForm" method="post" action="/controllers/admin/rewards/cashback/program_save.php" novalidate>
        <input type="hidden" name="type" value="cashback">
        <div class="form-grid">
          <div class="form-col">
            <label for="name">Program name</label>
            <input id="name" name="name" type="text" class="input-name" placeholder="Program name" value="<?= h($prefName) ?>" required>
            <div class="helper">Shown in reports and lists.</div>

            <label for="status" style="margin-top:10px">Status</label>
            <select id="status" name="status" class="slim">
              <option value="active"   <?= $prefStatus==='active'?'selected':''; ?>>Active</option>
              <option value="paused"   <?= $prefStatus==='paused'?'selected':''; ?>>Paused</option>
              <option value="inactive" <?= $prefStatus==='inactive'?'selected':''; ?>>Inactive</option>
            </select>

            <div class="row" style="margin-top:10px">
              <div style="flex:1;min-width:180px">
                <label for="start_at">Goes live Date</label>
                <input id="start_at" name="start_at" type="date" value="<?= h($prefStart) ?>" required>
                <div class="helper">Start date of the program.</div>
              </div>
              <div style="flex:1;min-width:180px">
                <label for="end_at">Ends Date</label>
                <input id="end_at" name="end_at" type="date" value="<?= h($prefEnd) ?>">
                <div class="helper">Leave empty for ongoing.</div>
              </div>
            </div>

            <div class="row" style="margin-top:10px">
              <div>
                <label for="award_timing">Award timing</label>
                <select id="award_timing" name="award_timing" class="slim">
                  <option value="on_payment" <?= $prefAward==='on_payment'?'selected':''; ?>>On payment</option>
                  <option value="on_close"   <?= $prefAward==='on_close'?'selected':''; ?>>On close</option>
                </select>
                <div class="helper">When cashback is issued.</div>
              </div>
              <div>
                <label for="rounding">Rounding</label>
                <select id="rounding" name="rounding" class="slim">
                  <option value="floor"   <?= $prefRound==='floor'?'selected':''; ?>>Floor</option>
                  <option value="nearest" <?= $prefRound==='nearest'?'selected':''; ?>>Nearest</option>
                  <option value="ceil"    <?= $prefRound==='ceil'?'selected':''; ?>>Ceil</option>
                </select>
              </div>
            </div>

            <div class="row" style="margin-top:10px">
              <div>
                <label for="max_redeem_percent">Max redeem %</label>
                <input id="max_redeem_percent" name="max_redeem_percent" type="number" step="0.01" min="0" class="w-sm" value="<?= h(number_format((float)$prefMaxPct,2,'.','')) ?>">
                <div class="helper">Cap of bill payable by cashback (e.g., 20%).</div>
              </div>
              <div>
                <label for="min_redeem_amount">Min redeem amount</label>
                <input id="min_redeem_amount" name="min_redeem_amount" type="number" step="0.01" min="0" class="w-sm" value="<?= h(number_format((float)$prefMinAmt,2,'.','')) ?>">
                <div class="helper">Minimum wallet balance to redeem.</div>
              </div>
            </div>

            <div class="row" style="margin-top:10px">
              <div>
                <label for="min_visit_redeem">Auto‑redeem from visit</label>
                <input id="min_visit_redeem" name="min_visit_redeem" type="number" min="1" step="1" class="w-xs" value="<?= (int)$prefMinVisitRedeem ?>">
              </div>
              <div>
                <label for="wallet_expiry_days">Wallet expiry (days)</label>
                <input id="wallet_expiry_days" name="wallet_expiry_days" type="number" min="0" step="1" class="w-xs" value="<?= (int)$prefWalletExpiry ?>">
              </div>
            </div>
          </div>

          <!-- RIGHT column -->
          <div class="form-col">
            <label>Visit ladder (max 8)</label>
            <div class="helper" style="margin-top:2px;">Set the cashback % and validity for each visit milestone. Use “N+” for the last tier.</div>

            <!-- Ladder with fixed header + scroll body -->
            <div class="ladder-wrap" id="ladder">
              <!-- Head -->
              <div class="ladder-head">
                <table aria-hidden="true">
                  <thead>
                    <tr>
                      <th style="width:40%;">Visit</th>
                      <th style="width:25%;">% of bill</th>
                      <th style="width:25%;">Validity (days)</th>
                      <th style="width:10%;">Action</th>
                    </tr>
                  </thead>
                </table>
              </div>
              <!-- Body -->
              <div class="ladder-body">
                <table aria-label="Visit ladder">
                  <tbody id="ladderTbody">
                  <?php foreach ($prefLadder as $i=>$row):
                    $v  = (string)($row['visit'] ?? '');
                    $rp = number_format((float)($row['rate_pct'] ?? 0), 2, '.', '');
                    $vd = (int)($row['valid_days'] ?? 1);
                  ?>
                    <tr>
                      <!-- VISIT field now uses the same exact style as Program name -->
                      <td style="width:40%;"><input name="ladder[<?= $i ?>][visit]" value="<?= h($v) ?>" class="input-name" placeholder="1 or 4+" required></td>
                      <td style="width:25%;"><input name="ladder[<?= $i ?>][rate_pct]" type="number" step="0.01" min="0" max="100" value="<?= h($rp) ?>" required class="w-sm"></td>
                      <td style="width:25%;"><input name="ladder[<?= $i ?>][valid_days]" type="number" min="1" step="1" value="<?= h((string)$vd) ?>" required class="w-sm"></td>
                      <td style="width:10%;text-align:right;"><button type="button" class="btn small js-del-row">Delete</button></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="row" style="margin-top:8px">
              <button type="button" id="addRow" class="btn small">Add Visit</button>
              <span class="helper">Max 8 visits. Use <strong>N+</strong> to continue for all later visits.</span>
            </div>

            <!-- Channels & Exclusions below ladder -->
            <div style="margin-top:12px">
              <label>Sales channels</label>
              <div class="row">
                <label><input type="checkbox" name="channels[]" value="pos" checked> POS</label>
                <label><input type="checkbox" name="channels[]" value="online" checked> Online</label>
              </div>
              <div class="helper">Select where cashback applies.</div>
            </div>

            <div style="margin-top:10px">
              <label>Exclusions</label>
              <div class="row">
                <label><input type="checkbox" name="excl_aggregators" value="1"> Aggregator</label>
                <label><input type="checkbox" name="excl_discounted"  value="1"> Orders</label>
              </div>
              <div class="helper">Exclude delivery aggregators or discounted orders from earning.</div>
            </div>
          </div>
        </div>

        <div class="btnbar">
          <button type="submit" class="btn primary" name="action" value="create">Create</button>
          <a class="btn" href="#programs">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Slide-over (Ledger / Adjust) -->
<div id="slideover" class="panel" aria-hidden="true">
  <div class="panel-hd">
    <div class="row">
      <strong id="panelTitle">Customer</strong>
      <span id="panelId" class="badge">#</span>
    </div>
    <button class="btn small" id="panelClose" aria-label="Close">Close</button>
  </div>
  <div class="panel-bd">
    <div class="tabs">
      <div class="tab active" id="tabLedger">Ledger</div>
      <div class="tab" id="tabAdjust">Adjust</div>
    </div>

    <div id="paneLedger">
      <div class="muted" style="margin-bottom:8px">Showing recent entries.</div>
      <div id="ledgerList" class="notice">Loading…</div>
    </div>

    <div id="paneAdjust" style="display:none">
      <form id="adjustForm" class="form-grid">
        <div class="form-col">
          <label>Type</label>
          <div class="row">
            <label><input type="radio" name="adj_type" value="credit" checked> Credit (+)</label>
            <label style="margin-left:12px"><input type="radio" name="adj_type" value="debit"> Debit (−)</label>
          </div>
        </div>
        <div class="form-col">
          <label for="adj_amount">Amount</label>
          <input id="adj_amount" name="amount" type="number" min="0.01" step="0.01" required class="w-sm">
        </div>
        <div class="form-col" style="grid-column:1/-1">
          <label for="adj_reason">Reason</label>
          <input id="adj_reason" name="reason" type="text" placeholder="Reason (required)" required>
        </div>
        <div class="btnbar" style="grid-column:1/-1">
          <button type="submit" class="btn primary">Save adjustment</button>
        </div>
      </form>
      <div class="notice" id="adjustMsg" style="display:none;margin-top:10px"></div>
    </div>
  </div>
</div>
<div id="backdrop" class="panel-backdrop" aria-hidden="true"></div>

<script>
(function(){
  // Live filters
  function attachLiveFilter(input, table){
    const tbody = table.querySelector('tbody');
    input.addEventListener('input', function(){
      const q = this.value.trim().toLowerCase();
      Array.from(tbody.rows).forEach(row=>{
        if (row.querySelector('td[colspan]')) return;
        const txt = row.innerText.toLowerCase();
        row.style.display = txt.includes(q) ? '' : 'none';
      });
    });
  }
  const fp=document.getElementById('filter-programs'); const tp=document.getElementById('table-programs');
  const fc=document.getElementById('filter-customers'); const tc=document.getElementById('table-customers');
  const ft=document.getElementById('filter-tx'); const tt=document.getElementById('table-tx');
  if(fp&&tp) attachLiveFilter(fp,tp);
  if(fc&&tc) attachLiveFilter(fc,tc);
  if(ft&&tt) attachLiveFilter(ft,tt);

  // Delete program
  async function requestDelete(programId){
    try{
      const res = await fetch('/controllers/admin/rewards/cashback/program_delete.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: 'program_id=' + encodeURIComponent(programId)
      });
      if (!res.ok) throw new Error('Server responded ' + res.status);
      const data = await res.json();
      if (data && data.ok) {
        alert('Program deleted.');
        location.reload();
      } else {
        alert(data && data.error ? data.error : 'Delete failed.');
      }
    } catch (e){
      alert('Delete error: ' + (e?.message || e));
    }
  }
  document.querySelectorAll('.js-delete-prog').forEach(btn=>{
    btn.addEventListener('click', ()=>{
      const pid = btn.getAttribute('data-id');
      if (!pid) return;
      if (confirm('Delete this program? This action cannot be undone.')) requestDelete(pid);
    });
  });

  // Slide-over logic
  const slideover=document.getElementById('slideover');
  const backdrop=document.getElementById('backdrop');
  const panelClose=document.getElementById('panelClose');
  const panelTitle=document.getElementById('panelTitle');
  const panelId=document.getElementById('panelId');
  const tabLedger=document.getElementById('tabLedger');
  const tabAdjust=document.getElementById('tabAdjust');
  const paneLedger=document.getElementById('paneLedger');
  const paneAdjust=document.getElementById('paneAdjust');
  const ledgerList=document.getElementById('ledgerList');
  const adjustForm=document.getElementById('adjustForm');
  const programId = parseInt('<?= (int)$selectedProgramId ?>',10) || 0;

  function openPanel(title, id, which){
    panelTitle.textContent = title || 'Customer';
    panelId.textContent = '#'+id;
    slideover.classList.add('open'); slideover.setAttribute('aria-hidden','false');
    backdrop.style.display='block';
    setTab(which||'ledger');
    if (which==='adjust') document.getElementById('adj_amount')?.focus();
  }
  function closePanel(){
    slideover.classList.remove('open'); slideover.setAttribute('aria-hidden','true');
    backdrop.style.display='none';
  }
  panelClose.addEventListener('click', closePanel);
  backdrop.addEventListener('click', closePanel);
  document.addEventListener('keydown',(e)=>{ if(e.key==='Escape') closePanel(); });

  function setTab(which){
    if (which==='adjust'){
      tabAdjust.classList.add('active'); tabLedger.classList.remove('active');
      paneAdjust.style.display='block'; paneLedger.style.display='none';
    } else {
      tabLedger.classList.add('active'); tabAdjust.classList.remove('active');
      paneLedger.style.display='block'; paneAdjust.style.display='none';
    }
  }
  tabLedger.addEventListener('click', ()=>setTab('ledger'));
  tabAdjust.addEventListener('click', ()=>setTab('adjust'));

  // Bind actions in Customers table
  document.querySelectorAll('.js-ledger').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      const tr=btn.closest('tr'); const id=parseInt(btn.getAttribute('data-id'),10);
      const name = tr.querySelector('td div').textContent.trim();
      openPanel(name, id, 'ledger'); loadLedger(id);
    });
  });
  document.querySelectorAll('.js-adjust').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      const tr=btn.closest('tr'); const id=parseInt(btn.getAttribute('data-id'),10);
      const name = tr.querySelector('td div').textContent.trim();
      openPanel(name, id, 'adjust'); loadLedger(id);
      document.getElementById('adj_amount')?.focus();
    });
  });

  async function loadLedger(customerId){
    ledgerList.textContent='Loading…';
    const params = new URLSearchParams({ customer_id:String(customerId), program_id:String(programId) });
    try{
      const r = await fetch('/controllers/admin/rewards/cashback/ledger_list.php?'+params.toString(), {credentials:'same-origin'});
      if (!r.ok) throw new Error('HTTP '+r.status);
      const html = await r.text();
      ledgerList.innerHTML = html;
    }catch(err){
      ledgerList.innerHTML = '<div class="notice">Could not load ledger.<br>'+String(err)+'</div>';
    }
  }

  // Ladder dynamic rows
  const ladderTbody = document.getElementById('ladderTbody');
  const addRowBtn   = document.getElementById('addRow');
  function wireDelete(btn){
    btn.addEventListener('click', ()=> {
      const tr = btn.closest('tr'); if (!tr) return;
      tr.remove();
    });
  }
  ladderTbody.querySelectorAll('.js-del-row').forEach(wireDelete);

  addRowBtn?.addEventListener('click', ()=>{
    const cnt = ladderTbody.querySelectorAll('tr').length;
    if (cnt >= 8) { alert('Maximum 8 visits.'); return; }
    const i = Date.now();
    const tr = document.createElement('tr');
    tr.innerHTML =
      '<td style="width:40%;"><input name="ladder['+i+'][visit]" class="input-name" placeholder="5+" required></td>'+
      '<td style="width:25%;"><input name="ladder['+i+'][rate_pct]" type="number" step="0.01" min="0" max="100" value="0.00" required class="w-sm"></td>'+
      '<td style="width:25%;"><input name="ladder['+i+'][valid_days]" type="number" min="1" step="1" value="1" required class="w-sm"></td>'+
      '<td style="width:10%;text-align:right;"><button type="button" class="btn small js-del-row">Delete</button></td>';
    ladderTbody.appendChild(tr);
    wireDelete(tr.querySelector('.js-del-row'));
  });

  // Form validation
  const form = document.getElementById('programForm');
  form?.addEventListener('submit', function(ev){
    const name = document.getElementById('name');
    const s = document.getElementById('start_at');
    const e = document.getElementById('end_at');
    function ymd(v){ const t=(v||'').split('-'); return t.length===3? new Date(+t[0], +t[1]-1, +t[2]) : null; }
    function invalid(m, el){ alert(m); el && el.focus(); }
    if (!name.value.trim()) { ev.preventDefault(); return invalid('Program name is required.', name); }
    const sd = ymd(s.value), ed = ymd(e.value);
    if (!sd) { ev.preventDefault(); return invalid('Please choose the Goes live date.', s); }
    if (e.value && ed && ed.getTime() < sd.getTime()) { ev.preventDefault(); return invalid('Ends date must not be before Goes live date.', e); }

    // Ladder checks
    const rows = Array.from(document.querySelectorAll('#ladderTbody tr'));
    if (rows.length === 0){ ev.preventDefault(); return invalid('Please add at least one ladder row.', document.getElementById('addRow')); }
    if (rows.length > 8){ ev.preventDefault(); return invalid('Maximum 8 ladder rows.', document.getElementById('addRow')); }
    for (const r of rows){
      const visit = r.querySelector('input[name*="[visit]"]')?.value.trim();
      const rate  = parseFloat(r.querySelector('input[name*="[rate_pct]"]')?.value || '0');
      const valid = parseInt(r.querySelector('input[name*="[valid_days]"]')?.value || '0', 10);
      if (!visit || !/^\d+(\+)?$/.test(visit)){ ev.preventDefault(); return invalid('Visit must be like "1" or "3+"', r.querySelector('input[name*="[visit]"]')); }
      if (!(rate>=0 && rate<=100)){ ev.preventDefault(); return invalid('% of bill must be between 0 and 100', r.querySelector('input[name*="[rate_pct]"]')); }
      if (!(valid>=1)){ ev.preventDefault(); return invalid('Validity days must be at least 1', r.querySelector('input[name*="[valid_days]"]')); }
    }
  });
})();
</script>
</body>
</html>