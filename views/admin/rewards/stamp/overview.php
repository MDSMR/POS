<?php
// public_html/views/admin/rewards/stamp/overview.php
// Rewards → Stamp (Stamps, Customers Balances, Stamps transactions, Program Setup)
// - Auto-redeem: specific product(s) (configured in Program)
// - Customers table actions: Ledger, Adjust (Redeem removed)
// - Slide-over panel for in-page Ledger & Adjust
// - Uses shared admin navigation bar include (same pattern as rewards index / catalog)
declare(strict_types=1);

/* Debug */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* Bootstrap (match rewards index pattern; NOTE: this file is deeper → ../../../../config/db.php) */
$bootstrap_warning = '';
$bootstrap_ok = false;

/* Resolve /config/db.php from /views/admin/rewards/stamp */
$bootstrap_tried = [];
$bootstrap_path  = __DIR__ . '/../../../../config/db.php'; // up 4 → /public_html/config/db.php
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
  $prevHandler = set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
  });
  try {
    require_once $bootstrap_path; // db(), use_backend_session()
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    } else {
      $bootstrap_ok = true;
    }
  } catch (Throwable $e) {
    $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
  } finally {
    if ($prevHandler) { set_error_handler($prevHandler); }
  }
}

/* Session */
if ($bootstrap_ok) {
  try { use_backend_session(); } catch (Throwable $e) {
    $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: ' . $e->getMessage());
  }
}
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

$tenantId = (int)($user['tenant_id'] ?? 0);
$db = db();

/* Inputs & paging */
$todayYmd = (new DateTime('today'))->format('Y-m-d');
$pagePrograms  = max(1, (int)($_GET['p_programs'] ?? 1));
$pageCustomers = max(1, (int)($_GET['p_customers'] ?? 1));
$pageTx        = max(1, (int)($_GET['p_tx'] ?? 1));
$limit = 12;
$offsetPrograms  = ($pagePrograms-1)*$limit;
$offsetCustomers = ($pageCustomers-1)*$limit;
$offsetTx        = ($pageTx-1)*$limit;

/* Active program context:
   - If ?program_id is in the URL (from "Open"), use it.
   - Else pick the latest active program within date range, else latest created. */
$selectedProgramId = (int)($_GET['program_id'] ?? 0);
try {
  if ($selectedProgramId <= 0) {
    $stmt = $db->prepare("SELECT id FROM loyalty_programs
                          WHERE tenant_id=? AND type='stamp'
                            AND status='active'
                            AND (start_at IS NULL OR start_at<=?)
                            AND (end_at   IS NULL OR end_at  >=?)
                          ORDER BY id DESC LIMIT 1");
    $stmt->execute([$tenantId, $todayYmd, $todayYmd]);
    $selectedProgramId = (int)($stmt->fetchColumn() ?: 0);
    if ($selectedProgramId <= 0) {
      $stmt = $db->prepare("SELECT id FROM loyalty_programs WHERE tenant_id=? AND type='stamp' ORDER BY id DESC LIMIT 1");
      $stmt->execute([$tenantId]);
      $selectedProgramId = (int)($stmt->fetchColumn() ?: 0);
    }
  }
} catch (Throwable $e) { if ($DEBUG) echo '<pre>Program pick error: '.htmlspecialchars($e->getMessage()).'</pre>'; }

/* Programs list (NOTE: still selecting reward_item_id for display compatibility; multi-reward UI is in the form below) */
$programs = []; $programsTotal = 0;
try {
  $sql = "SELECT SQL_CALC_FOUND_ROWS id, name, status, start_at, end_at, stamps_required, reward_item_id, per_visit_cap
          FROM loyalty_programs
          WHERE tenant_id = :t AND type = 'stamp'
          ORDER BY id DESC
          LIMIT :o, :l";
  $stmt = $db->prepare($sql);
  $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
  $stmt->bindValue(':o', $offsetPrograms, PDO::PARAM_INT);
  $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
  $stmt->execute();
  $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
  $programsTotal = (int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();
} catch (Throwable $e) { if ($DEBUG) echo '<pre>Programs error: '.htmlspecialchars($e->getMessage()).'</pre>'; }

/* Products for tag dropdowns */
$products = [];
try {
  $p = $db->prepare("SELECT id, name_en FROM products WHERE tenant_id=? AND is_active=1 ORDER BY name_en ASC");
  $p->execute([$tenantId]);
  $products = $p->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

/* Customers balances for selected program (includes phone/mobile) */
$customers = []; $customersTotal = 0;
try {
  if ($selectedProgramId > 0) {
    $sql = "
      SELECT SQL_CALC_FOUND_ROWS c.id, c.name, c.phone,
        COALESCE((
          SELECT SUM(CASE WHEN ll.direction='redeem' THEN -ll.amount ELSE ll.amount END)
          FROM loyalty_ledgers ll
          WHERE ll.tenant_id = :t AND ll.program_type='stamp' AND ll.program_id = :pid AND ll.customer_id = c.id
        ), 0) AS balance
      FROM customers c
      WHERE c.tenant_id = :t
      ORDER BY c.id DESC
      LIMIT :o, :l
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
    $stmt->bindValue(':pid', $selectedProgramId, PDO::PARAM_INT);
    $stmt->bindValue(':o', $offsetCustomers, PDO::PARAM_INT);
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $customersTotal = (int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();
  }
} catch (Throwable $e) { if ($DEBUG) echo '<pre>Customers error: '.htmlspecialchars($e->getMessage()).'</pre>'; }

/* Transactions for selected program */
$tx = []; $txTotal = 0;
try {
  if ($selectedProgramId > 0) {
    $sql = "
      SELECT SQL_CALC_FOUND_ROWS ll.id, ll.created_at, ll.direction, ll.amount, ll.order_id, ll.user_id,
             c.name AS customer_name, c.id AS customer_id
      FROM loyalty_ledgers ll
      LEFT JOIN customers c ON c.id = ll.customer_id
      WHERE ll.tenant_id=:t AND ll.program_type='stamp' AND ll.program_id=:pid
      ORDER BY ll.id DESC
      LIMIT :o, :l
    ";
    $stmt = $db->prepare($sql);
    $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
    $stmt->bindValue(':pid', $selectedProgramId, PDO::PARAM_INT);
    $stmt->bindValue(':o', $offsetTx, PDO::PARAM_INT);
    $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $tx = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $txTotal = (int)$db->query("SELECT FOUND_ROWS()")->fetchColumn();
  }
} catch (Throwable $e) { if ($DEBUG) echo '<pre>Transactions error: '.htmlspecialchars($e->getMessage()).'</pre>'; }

/* Helpers */
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pagelinks($total, $page, $limit, $qsKey){
  $pages = max(1, (int)ceil($total / $limit));
  if ($pages <= 1) return '';
  $qs = $_GET; unset($qs[$qsKey]); // will set below
  $base = strtok($_SERVER['REQUEST_URI'], '?');
  $out = '<div class="pager">';
  if ($page > 1) { $qs[$qsKey] = $page-1; $out .= '<a class="btn" href="'.$base.'?'.http_build_query($qs).'">Prev</a>'; }
  $out .= '<span class="muted">Page '.$page.' of '.$pages.'</span>';
  if ($page < $pages) { $qs[$qsKey] = $page+1; $out .= '<a class="btn" href="'.$base.'?'.http_build_query($qs).'">Next</a>'; }
  return $out.'</div>';
}

/* Duplicate defaults (Program Setup pre-fill) */
$dupId = isset($_GET['duplicate']) ? (int)$_GET['duplicate'] : 0;
$dup = null;
if ($dupId > 0) {
  try {
    $s = $db->prepare("SELECT * FROM loyalty_programs WHERE id=? AND tenant_id=? AND type='stamp'");
    $s->execute([$dupId, $tenantId]);
    $dup = $s->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {}
}
$prefName    = $dup ? ((string)$dup['name'].' (Copy)') : 'Program name';
$prefStamps  = $dup ? (int)$dup['stamps_required'] : 10;
$prefCap     = $dup ? (int)($dup['per_visit_cap'] ?? 1) : 1;
$prefCarry   = $dup ? (int)($dup['carry_over'] ?? 1) : 1;
$prefStart   = $dup ? (string)($dup['start_at'] ?? $todayYmd) : $todayYmd;
$prefEnd     = $dup ? (string)($dup['end_at'] ?? '') : '';
$prefStatus  = $dup ? (string)($dup['status'] ?? 'active') : 'active';

/* Preselected arrays for tag inputs (read from legacy earn_scope_json if present) */
$prefRewardIds = []; // we will submit reward_item_ids[] (multi)
$prefEarnIds   = []; // submit earn_item_ids[] (multi)
if ($dup) {
  // Backward compatibility: if reward_item_id exists, seed reward list with it
  if (!empty($dup['reward_item_id'])) { $prefRewardIds[] = (int)$dup['reward_item_id']; }
  if (!empty($dup['earn_scope_json'])) {
    $json = json_decode((string)$dup['earn_scope_json'], true);
    if (is_array($json)) {
      foreach ((array)($json['products'] ?? []) as $pid) $prefEarnIds[] = (int)$pid;
    }
  }
}

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Stamp · Smorll POS</title>
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
.btn{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border-radius:10px;border:1px solid var(--border);background:#fff;font-weight:700;text-decoration:none}
.btn:hover{background:var(--hover)}
.btn.primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.btn.small{padding:6px 9px;font-weight:700;font-size:12.5px}
input[type="text"],input[type="number"],input[type="date"],input[type="search"],select,textarea{
  width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;font-size:14px
}
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

/* Slide-over panel */
.panel-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.52);display:none}
.panel{position:fixed;top:0;right:0;height:100%;width:520px;max-width:100%;background:#fff;border-left:1px solid var(--border);transform:translateX(100%);transition:transform .22s ease;display:flex;flex-direction:column;z-index:50}
.panel.open + .panel-backdrop{display:block}
.panel.open{transform:translateX(0)}
.panel-hd{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.panel-bd{padding:12px 14px;overflow:auto}
.tabs{display:flex;gap:8px;border-bottom:1px solid var(--border);margin-bottom:10px}
.tab{padding:8px 10px;border:1px solid var(--border);border-bottom:none;border-radius:10px 10px 0 0;background:#f8fafc;cursor:pointer}
.tab.active{background:#fff;font-weight:800}

/* Program Setup layout */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.form-col{flex:1;min-width:220px}
.btnbar{display:flex;gap:10px;justify-content:center;margin-top:10px}

/* Header right group spacing */
.header-actions{display:flex;gap:10px;align-items:center}

/* Tag dropdown styles */
.tagbox{position:relative}
.tagbox .tags{display:flex;flex-wrap:wrap;gap:6px;margin:6px 0 0}
.tag{display:inline-flex;align-items:center;gap:6px;background:#eef2ff;border:1px solid #e0e7ff;border-radius:999px;padding:4px 8px;font-size:12.5px}
.tag button{border:none;background:transparent;cursor:pointer;font-weight:700}
.tagbox .control{display:flex;gap:8px;align-items:center}
.tagbox input[type="text"]{flex:1}
.dropdown{position:absolute;z-index:10;left:0;right:0;top:100%;background:#fff;border:1px solid var(--border);border-radius:10px;margin-top:6px;max-height:220px;overflow:auto;display:none}
.dropdown.open{display:block}
.dropdown .opt{padding:8px 10px;cursor:pointer}
.dropdown .opt:hover{background:#f9fafb}
.muted-sm{color:var(--muted);font-size:12.5px;margin-top:4px}
</style>
</head>
<body>

<?php
/* Robust admin_nav include with graceful fallback, like rewards index */
$active = 'rewards_stamp';
$nav_tried = [];
$nav_included = false;

$nav1 = __DIR__ . '/../../partials/admin_nav.php';           // /views/admin/partials/admin_nav.php
$nav2 = dirname(__DIR__, 3) . '/partials/admin_nav.php';     // /views/partials/admin_nav.php (alt)
$nav_tried[] = $nav1;
if (is_file($nav1)) {
  $nav_included = (bool) @include $nav1;
} else {
  $nav_tried[] = $nav2;
  if (is_file($nav2)) {
    $nav_included = (bool) @include $nav2;
  }
}
if (!$nav_included): ?>
  <div class="notice" style="max-width:1150px;margin:10px auto;padding:10px 16px;">
    Navigation header not found. Looked for:
    <div style="margin-top:6px">
      <code><?= h($nav1) ?></code><br>
      <code><?= h($nav2) ?></code>
    </div>
  </div>
<?php endif; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?>
    <div class="notice"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>

  <div class="h1">Stamp Rewards</div>
  <div class="muted">Manage stamp programs, view customer balances, track stamp transactions, and configure auto‑redeem for specific items.</div>

  <!-- 1) Stamps (Programs) -->
  <div class="card" id="programs">
    <div class="hd">
      <strong>Stamps</strong>
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
            <th>Stamps</th>
            <th>Reward product</th>
            <th class="left" style="width:220px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$programs): ?>
            <tr><td colspan="7" style="padding:16px">No stamp programs yet.</td></tr>
          <?php else: foreach ($programs as $p):
            $status = (string)($p['status'] ?? '');
            $statusClass = $status==='active' ? 'good' : ($status==='paused' ? 'warn' : 'bad');
            $rewardName = '—';
            if (!empty($p['reward_item_id'])) {
              try {
                $rr = $db->prepare("SELECT name_en FROM products WHERE id=? AND tenant_id=?");
                $rr->execute([(int)$p['reward_item_id'], $tenantId]);
                $rewardName = (string)($rr->fetchColumn() ?: 'Product #'.(int)$p['reward_item_id']);
              } catch (Throwable $e) {}
            }
          ?>
            <tr>
              <td style="border-top:none">
                <div style="font-weight:800"><?= h($p['name']) ?></div>
                <div class="muted">ID #<?= (int)$p['id'] ?> · Per visit cap: <?= (int)$p['per_visit_cap'] ?></div>
              </td>
              <td class="status <?= $statusClass ?>" style="border-top:none"><?= h($status ?: 'inactive') ?></td>
              <td style="border-top:none"><?= h($p['start_at'] ?: '—') ?></td>
              <td style="border-top:none"><?= h($p['end_at'] ?: '—') ?></td>
              <td style="border-top:none"><?= (int)$p['stamps_required'] ?></td>
              <td style="border-top:none"><?= h($rewardName) ?></td>
              <td class="left" style="border-top:none">
                <a class="btn small" href="/views/admin/rewards/stamp/overview.php?program_id=<?= (int)$p['id'] ?>#setup">Open</a>
                <a class="btn small" href="/views/admin/rewards/stamp/overview.php?program_id=<?= (int)$p['id'] ?>&duplicate=<?= (int)$p['id'] ?>#setup">Duplicate</a>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?= pagelinks($programsTotal, $pagePrograms, $limit, 'p_programs'); ?>
    </div>
  </div>

  <!-- 2) Customers Balances -->
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
            <th class="right" style="width:90px">Stamps</th>
            <th class="left" style="width:260px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($selectedProgramId <= 0): ?>
            <tr><td colspan="4" style="padding:16px">No program selected. Click “Open” in the Stamps table to choose one.</td></tr>
          <?php elseif (!$customers): ?>
            <tr><td colspan="4" style="padding:16px">No customers found.</td></tr>
          <?php else: foreach ($customers as $c): ?>
            <tr data-customer="<?= (int)$c['id'] ?>">
              <td style="border-top:none">
                <div style="font-weight:800"><?= h($c['name'] ?: ('ID #'.(int)$c['id'])) ?></div>
                <div class="muted">ID #<?= (int)$c['id'] ?></div>
              </td>
              <td class="left" style="border-top:none"><?= h($c['phone'] ?? '—') ?></td>
              <td class="right" style="border-top:none"><span class="cust-balance"><?= (int)($c['balance'] ?? 0) ?></span></td>
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

  <!-- 3) Stamps transactions -->
  <div class="card" id="transactions">
    <div class="hd">
      <strong>Stamps transactions</strong>
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
            <tr><td colspan="6" style="padding:16px">No program selected. Click “Open” in the Stamps table to choose one.</td></tr>
          <?php elseif (!$tx): ?>
            <tr><td colspan="6" style="padding:16px">No transactions found.</td></tr>
          <?php else: foreach ($tx as $t): ?>
            <tr>
              <td style="border-top:none"><?= h($t['created_at']) ?></td>
              <td style="border-top:none"><?= h(($t['customer_name'] ?: 'ID #'.(int)$t['customer_id'])) ?></td>
              <td style="border-top:none"><?= h($t['direction']) ?></td>
              <td class="right" style="border-top:none"><?= (int)$t['amount'] ?></td>
              <td style="border-top:none"><?= $t['order_id'] ? ('#'.(int)$t['order_id']) : '—' ?></td>
              <td style="border-top:none"><?= $t['user_id'] ? ('#'.(int)$t['user_id']) : '—' ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
      <?= pagelinks($txTotal, $pageTx, $limit, 'p_tx'); ?>
    </div>
  </div>

  <!-- 4) Program Setup -->
  <div class="card" id="setup">
    <div class="hd"><strong>Program Setup</strong></div>
    <div class="bd">
      <form id="programForm" method="post" action="/controllers/admin/rewards/stamp/program_save.php" novalidate>
        <input type="hidden" name="type" value="stamp">
        <input type="hidden" name="auto_redeem" value="1">
        <input type="hidden" name="reward_mode" value="specific_item">

        <div class="form-grid">
          <div class="form-col">
            <label for="name">Program name</label>
            <input id="name" name="name" type="text" placeholder="Program name" value="<?= h($prefName) ?>" required>
          </div>

          <div class="form-col">
            <label>Reward items</label>
            <div class="tagbox" data-name="reward_item_ids[]">
              <div class="control">
                <input type="text" class="tag-search" placeholder="Search items to add…">
                <button type="button" class="btn small js-clear">Clear</button>
              </div>
              <div class="dropdown"></div>
              <div class="tags"></div>
            </div>
            <div class="muted-sm">Choose one or more items that can be auto‑redeemed.</div>
          </div>

          <div class="form-col">
            <label for="stamps_required">Stamps required</label>
            <input id="stamps_required" name="stamps_required" type="number" min="1" step="1" value="<?= (int)$prefStamps ?>" required>
          </div>
          <div class="form-col">
            <label for="per_visit_cap">Per visit cap</label>
            <input id="per_visit_cap" name="per_visit_cap" type="number" min="1" step="1" value="<?= (int)$prefCap ?>">
          </div>

          <div class="form-col">
            <label>Earning items</label>
            <div class="tagbox" data-name="earn_item_ids[]">
              <div class="control">
                <input type="text" class="tag-search" placeholder="Search items to add…">
                <button type="button" class="btn small js-clear">Clear</button>
              </div>
              <div class="dropdown"></div>
              <div class="tags"></div>
            </div>
            <div class="muted-sm">Each unit of these items earns 1 stamp.</div>
          </div>

          <div class="form-col">
            <label for="carry_over">Carry over</label>
            <select id="carry_over" name="carry_over">
              <option value="1" <?= $prefCarry? 'selected':''; ?>>On (keep leftovers)</option>
              <option value="0" <?= !$prefCarry? 'selected':''; ?>>Off</option>
            </select>
          </div>

          <div class="form-col">
            <label for="status">Status</label>
            <select id="status" name="status">
              <option value="active"   <?= $prefStatus==='active'?'selected':''; ?>>Active</option>
              <option value="paused"   <?= $prefStatus==='paused'?'selected':''; ?>>Paused</option>
              <option value="inactive" <?= $prefStatus==='inactive'?'selected':''; ?>>Inactive</option>
            </select>
          </div>

          <div class="form-col">
            <label for="start_at">Goes live Date</label>
            <input id="start_at" name="start_at" type="date" value="<?= h($prefStart) ?>" required>
          </div>
          <div class="form-col">
            <label for="end_at">Ends Date</label>
            <input id="end_at" name="end_at" type="date" value="<?= h($prefEnd) ?>">
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

<!-- Slide-over Panel (Ledger / Adjust) -->
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
          <label for="adj_amount">Amount (stamps)</label>
          <input id="adj_amount" name="amount" type="number" min="1" step="1" required>
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
  // Products payload from PHP for tag dropdowns
  const PRODUCTS = <?php
    $arr = [];
    foreach ($products as $pr) {
      $arr[] = ['id' => (int)$pr['id'], 'name' => (string)$pr['name_en']];
    }
    echo json_encode($arr, JSON_UNESCAPED_UNICODE);
  ?>;

  const PREF_REWARD = <?php echo json_encode(array_values(array_unique(array_filter($prefRewardIds, 'is_int')))); ?>;
  const PREF_EARN   = <?php echo json_encode(array_values(array_unique(array_filter($prefEarnIds, 'is_int')))); ?>;

  // Live filter helper
  function attachLiveFilter(input, table){
    const tbody = table.querySelector('tbody');
    input.addEventListener('input', function(){
      const q = this.value.trim().toLowerCase();
      Array.from(tbody.rows).forEach(row=>{
        if (row.querySelector('td[colspan]')) return; // skip empty-state rows
        const txt = row.innerText.toLowerCase();
        row.style.display = txt.includes(q) ? '' : 'none';
      });
    });
  }

  // Programs live filter
  const fp = document.getElementById('filter-programs');
  const tp = document.getElementById('table-programs');
  if (fp && tp) attachLiveFilter(fp, tp);

  // Customers live filter
  const fc = document.getElementById('filter-customers');
  const tc = document.getElementById('table-customers');
  if (fc && tc) attachLiveFilter(fc, tc);

  // Transactions live filter
  const ft = document.getElementById('filter-tx');
  const tt = document.getElementById('table-tx');
  if (ft && tt) attachLiveFilter(ft, tt);

  // Simple Tag Dropdown component (vanilla JS)
  function TagBox(root, initialIds){
    const name = root.dataset.name; // e.g., reward_item_ids[]
    const input = root.querySelector('.tag-search');
    const dropdown = root.querySelector('.dropdown');
    const tags = root.querySelector('.tags');
    const clearBtn = root.querySelector('.js-clear');
    const selected = new Map(); // id -> {id,name}

    function syncHidden(){
      // Remove existing hidden inputs
      root.querySelectorAll('input[type="hidden"][data-tag]').forEach(n=>n.remove());
      // Append new ones
      for (const id of selected.keys()){
        const h = document.createElement('input');
        h.type = 'hidden';
        h.name = name;
        h.value = String(id);
        h.setAttribute('data-tag','1');
        root.appendChild(h);
      }
    }

    function renderTags(){
      tags.innerHTML = '';
      selected.forEach((obj)=>{
        const chip = document.createElement('span');
        chip.className = 'tag';
        // Removed (#id) from the chip label
        chip.innerHTML = `<span>${escapeHtml(obj.name)}</span> <button type="button" aria-label="Remove">×</button>`;
        chip.querySelector('button').addEventListener('click', ()=>{
          selected.delete(obj.id);
          syncHidden(); renderTags();
        });
        tags.appendChild(chip);
      });
    }

    function openDropdown(){ dropdown.classList.add('open'); }
    function closeDropdown(){ dropdown.classList.remove('open'); }
    function escapeHtml(s){ return s.replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }

    function filterList(q){
      const qq = q.trim().toLowerCase();
      const pool = PRODUCTS.filter(p => p.name.toLowerCase().includes(qq) && !selected.has(p.id));
      // Removed (#id) from the dropdown option label
      dropdown.innerHTML = pool.length ? pool.map(p => `<div class="opt" data-id="${p.id}" data-name="${escapeHtml(p.name)}">${escapeHtml(p.name)}</div>`).join('') : '<div class="opt" style="cursor:default">No matches</div>';
      dropdown.querySelectorAll('.opt[data-id]').forEach(el=>{
        el.addEventListener('click', ()=>{
          const id = parseInt(el.getAttribute('data-id'), 10);
          const nm = el.getAttribute('data-name');
          selected.set(id, {id, name:nm});
          input.value = '';
          closeDropdown();
          syncHidden(); renderTags();
        });
      });
    }

    input.addEventListener('focus', ()=>{ openDropdown(); filterList(input.value); });
    input.addEventListener('input', ()=>{ openDropdown(); filterList(input.value); });
    document.addEventListener('click', (e)=>{ if (!root.contains(e.target)) closeDropdown(); });
    clearBtn.addEventListener('click', ()=>{ selected.clear(); syncHidden(); renderTags(); input.value=''; });

    // Seed initial tags if any
    (initialIds||[]).forEach(id=>{
      const found = PRODUCTS.find(p=>p.id===id);
      if (found) selected.set(found.id, found);
    });
    syncHidden(); renderTags();
  }

  // Instantiate tag boxes
  document.querySelectorAll('.tagbox').forEach((el)=>{
    const isReward = el.dataset.name === 'reward_item_ids[]';
    const init = isReward ? PREF_REWARD : PREF_EARN;
    TagBox(el, init);
  });

  // Slide-over logic
  const slideover = document.getElementById('slideover');
  const backdrop  = document.getElementById('backdrop');
  const panelClose= document.getElementById('panelClose');
  const panelTitle= document.getElementById('panelTitle');
  const panelId   = document.getElementById('panelId');
  const tabLedger = document.getElementById('tabLedger');
  const tabAdjust = document.getElementById('tabAdjust');
  const paneLedger= document.getElementById('paneLedger');
  const paneAdjust= document.getElementById('paneAdjust');
  const ledgerList= document.getElementById('ledgerList');
  const adjustForm= document.getElementById('adjustForm');
  const adjustMsg = document.getElementById('adjustMsg');

  const programId = parseInt('<?= (int)$selectedProgramId ?>', 10) || 0;

  function openPanel(title, id, initialTab){
    panelTitle.textContent = title || 'Customer';
    panelId.textContent = '#'+id;
    slideover.classList.add('open');
    slideover.setAttribute('aria-hidden','false');
    backdrop.style.display='block';
    setTab(initialTab || 'ledger');
    if (initialTab === 'adjust') {
      document.getElementById('adj_amount').focus();
    }
  }
  function closePanel(){
    slideover.classList.remove('open');
    slideover.setAttribute('aria-hidden','true');
    backdrop.style.display='none';
  }
  panelClose.addEventListener('click', closePanel);
  backdrop.addEventListener('click', closePanel);
  document.addEventListener('keydown', (e)=>{ if(e.key==='Escape') closePanel(); });

  function setTab(which){
    if (which==='adjust') {
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
      const tr = btn.closest('tr');
      const id = parseInt(btn.getAttribute('data-id'),10);
      const name = tr.querySelector('td div').textContent.trim();
      openPanel(name, id, 'ledger');
      loadLedger(id);
      history.replaceState(null,'',location.pathname + '?' + new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)), customer_id:id}).toString() + '#ledger');
    });
  });
  document.querySelectorAll('.js-adjust').forEach(btn=>{
    btn.addEventListener('click', (e)=>{
      e.preventDefault();
      const tr = btn.closest('tr');
      const id = parseInt(btn.getAttribute('data-id'),10);
      const name = tr.querySelector('td div').textContent.trim();
      openPanel(name, id, 'adjust');
      loadLedger(id); // preload
      history.replaceState(null,'',location.pathname + '?' + new URLSearchParams({...Object.fromEntries(new URLSearchParams(location.search)), customer_id:id}).toString() + '#adjust');
      document.getElementById('adj_amount').focus();
    });
  });

  // Load ledger via AJAX
  async function loadLedger(customerId){
    ledgerList.textContent = 'Loading…';
    const params = new URLSearchParams({ customer_id: customerId, program_id: programId });
    try {
      const r = await fetch('/controllers/admin/rewards/stamp/ledger_list.php?'+params.toString(), {credentials:'same-origin'});
      if (!r.ok) throw new Error('HTTP '+r.status);
      const html = await r.text();
      ledgerList.innerHTML = html;
    } catch (err){
      ledgerList.innerHTML = '<div class="notice">Could not load ledger. Ensure the endpoint exists.<br>'+String(err)+'</div>';
    }
  }

  // Submit adjustment via AJAX
  adjustForm.addEventListener('submit', async function(e){
    e.preventDefault();
    adjustMsg.style.display='none';
    const cid = (new URLSearchParams(window.location.search)).get('customer_id');
    if (!cid || !programId) {
      adjustMsg.textContent = 'Missing customer or program.';
      adjustMsg.style.display='block';
      return;
    }
    const fd = new FormData(adjustForm);
    fd.append('customer_id', cid);
    fd.append('program_id', String(programId));
    try {
      const r = await fetch('/controllers/admin/rewards/stamp/adjustment_create.php', { method:'POST', body: fd, credentials:'same-origin' });
      const js = await r.json();
      if (!r.ok || !js || js.ok!==true) throw new Error(js && js.error ? js.error : ('HTTP '+r.status));
      adjustMsg.textContent = 'Adjustment saved.';
      adjustMsg.style.display='block';
      const bal = document.querySelector('tr[data-customer="'+cid+'"] .cust-balance');
      if (bal && js.balance !== undefined) bal.textContent = js.balance;
      loadLedger(parseInt(cid,10));
    } catch (err) {
      adjustMsg.textContent = 'Failed to save adjustment: '+String(err);
      adjustMsg.style.display='block';
    }
  });

  // Client validation for Program Setup
  const form = document.getElementById('programForm');
  form?.addEventListener('submit', function(ev){
    const name = document.getElementById('name');
    const req  = document.getElementById('stamps_required');
    const s = document.getElementById('start_at');
    const e = document.getElementById('end_at');
    function ymd(v){ const t=(v||'').split('-'); return t.length===3? new Date(+t[0], +t[1]-1, +t[2]) : null; }
    function invalid(m, el){ alert(m); el && el.focus(); }
    if (!name.value.trim()) { ev.preventDefault(); return invalid('Program name is required.', name); }
    if (!req.value || +req.value<1) { ev.preventDefault(); return invalid('Stamps required must be at least 1.', req); }
    const sd = ymd(s.value), ed = ymd(e.value);
    if (!sd) { ev.preventDefault(); return invalid('Please choose the Goes live date.', s); }
    if (e.value && ed && ed.getTime() < sd.getTime()) { ev.preventDefault(); return invalid('Ends date must not be before Goes live date.', e); }
  });
})();
</script>
</body>
</html>