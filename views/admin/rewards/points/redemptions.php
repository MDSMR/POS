<?php
// public_html/views/admin/rewards/points/redemptions.php — Points · Redemptions
// Safe bootstrap + session with robust path resolution and safe nav include
declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors', '1');
  @ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

$bootstrap_warning = '';
$bootstrap_ok = false;

/* ---------- Robust resolver for /public_html/config/db.php ---------- */
$bootstrap_tried = [];
$bootstrap_found = '';

/* Candidate A: expected relative from /views/admin/rewards/points → /config/db.php */
$candA = __DIR__ . '/../../../../config/db.php';
$bootstrap_tried[] = $candA;

/* Candidate B: DOCUMENT_ROOT/config/db.php */
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot !== '') {
  $candB = $docRoot . '/config/db.php';
  if (!in_array($candB, $bootstrap_tried, true)) $bootstrap_tried[] = $candB;
}

/* Candidate C: walk up 6 levels and try /config/db.php at each level (handles symlinks/hosting quirks) */
$cursor = __DIR__;
for ($i = 0; $i < 6; $i++) {
  $cursor = dirname($cursor);
  if ($cursor === '/' || $cursor === '.' || $cursor === '') break;
  $maybe = $cursor . '/config/db.php';
  if (!in_array($maybe, $bootstrap_tried, true)) $bootstrap_tried[] = $maybe;
}

/* Pick the first that exists */
foreach ($bootstrap_tried as $p) {
  if (is_file($p)) { $bootstrap_found = $p; break; }
}

if ($bootstrap_found === '') {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
  });
  try {
    require_once $bootstrap_found; // must define db(), use_backend_session()
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    } else {
      $bootstrap_ok = true;
    }
  } catch (Throwable $e) {
    $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
  } finally {
    if ($prevHandler) set_error_handler($prevHandler);
  }
}

if ($bootstrap_ok) {
  try { use_backend_session(); } catch (Throwable $e) {
    $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: ' . $e->getMessage());
  }
}

/* ---------- Auth ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Points · Redemptions · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
.container{max-width:1100px;margin:20px auto;padding:0 16px}

/* Cards / tiles */
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 6px}
.sub{color:var(--muted);font-size:13px;margin:0}

/* Controls */
.controls{display:flex;flex-wrap:wrap;gap:8px}
input[type="text"],select,input[type="date"]{
  padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:#fff;min-width:140px
}
.btn{display:inline-block;padding:8px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;text-decoration:none;color:var(--text);cursor:pointer}
.btn:hover{background:#f3f4f6}

/* Grid */
.grid{display:grid;gap:14px}
.grid-2{grid-template-columns:2fr 1fr}
@media (max-width:900px){.grid-2{grid-template-columns:1fr}}

/* Table */
.table-wrap{overflow:auto}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:10px 12px;border-bottom:1px solid var(--border);background:#fff;white-space:nowrap}
.table thead th{position:sticky;top:0;background:#f9fafb;font-weight:600}

/* Notices / debug */
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.debug{background:#eef2ff;border:1px solid #e0e7ff;color:#1e3a8a;padding:10px;border-radius:10px;margin:10px 0;font-size:12px}

.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid #e5e7eb}
.badge-pending{color:#92400e;background:#fef3c7;border-color:#fde68a}
.badge-approved{color:#065f46;background:#d1fae5;border-color:#a7f3d0}
.badge-void{color:#991b1b;background:#fee2e2;border-color:#fecaca}
</style>
</head>
<body>

<?php
/* Robust admin_nav include with graceful fallback */
$active = 'rewards';
$nav_tried = [];
$nav_included = false;

$nav1 = __DIR__ . '/../../partials/admin_nav.php';        // /views/admin/partials/admin_nav.php
$nav2 = dirname(__DIR__, 3) . '/partials/admin_nav.php';  // /views/partials/admin_nav.php
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
  <div class="notice" style="max-width:1100px;margin:10px auto;padding:10px 16px;">
    Navigation header not found. Looked for:
    <div style="margin-top:6px">
      <code><?= htmlspecialchars($nav1, ENT_QUOTES) ?></code><br>
      <code><?= htmlspecialchars($nav2, ENT_QUOTES) ?></code>
    </div>
  </div>
<?php endif; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?>
    <div class="notice"><?= htmlspecialchars($bootstrap_warning, ENT_QUOTES) ?></div>
  <?php endif; ?>

  <?php if ($DEBUG): ?>
    <div class="debug">
      <strong>Debug</strong><br>
      db.php tried:<br>
      <?php foreach ($bootstrap_tried as $p): ?>
        − <code><?= htmlspecialchars($p, ENT_QUOTES) ?></code><br>
      <?php endforeach; ?>
      <br>admin_nav tried:<br>
      <?php foreach ($nav_tried as $p): ?>
        − <code><?= htmlspecialchars($p, ENT_QUOTES) ?></code><br>
      <?php endforeach; ?>
      <br>Resolved db.php: <code><?= htmlspecialchars($bootstrap_found ?: 'NOT FOUND', ENT_QUOTES) ?></code>
    </div>
  <?php endif; ?>

  <!-- Page head -->
  <div class="card" style="margin-bottom:14px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div>
        <div class="h1">Points · Redemptions</div>
        <p class="sub">Review, approve, or void point redemptions. Use filters to find specific members, dates, or statuses.</p>
      </div>
      <div class="controls" role="group" aria-label="Page actions">
        <a class="btn" href="/views/admin/rewards/points/overview.php">Back to Points</a>
        <a class="btn" href="/views/admin/rewards/reports/overview.php">Rewards Reports</a>
      </div>
    </div>

    <!-- Filters -->
    <div class="controls" style="margin-top:10px;" role="search">
      <input type="text" placeholder="Search member / phone / voucher…" aria-label="Search">
      <select aria-label="Status">
        <option value="">All statuses</option>
        <option value="pending">Pending</option>
        <option value="approved">Approved</option>
        <option value="void">Void</option>
      </select>
      <input type="date" aria-label="From date">
      <input type="date" aria-label="To date">
      <button class="btn" type="button">Apply</button>
      <button class="btn" type="button">Reset</button>
    </div>
  </div>

  <!-- Table + side panel -->
  <div class="grid grid-2">
    <div class="card table-wrap">
      <table class="table" aria-label="Redemptions list">
        <thead>
          <tr>
            <th>#</th>
            <th>Member</th>
            <th>Voucher / Item</th>
            <th>Points</th>
            <th>Status</th>
            <th>Requested At</th>
            <th style="text-align:right;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <!-- Sample rows; replace with server-rendered data or AJAX -->
          <tr>
            <td>1027</td>
            <td>Amr M. · 0101****23</td>
            <td>Free Dessert (VCH-7F2A)</td>
            <td>150</td>
            <td><span class="badge badge-pending">Pending</span></td>
            <td>2025-08-18 13:42</td>
            <td style="text-align:right;">
              <button class="btn" type="button">Approve</button>
              <button class="btn" type="button">Void</button>
            </td>
          </tr>
          <tr>
            <td>1026</td>
            <td>Sarah K. · 0112****88</td>
            <td>10% Off (VCH-9C5Q)</td>
            <td>100</td>
            <td><span class="badge badge-approved">Approved</span></td>
            <td>2025-08-18 12:10</td>
            <td style="text-align:right;">
              <button class="btn" type="button">Revoke</button>
            </td>
          </tr>
          <tr>
            <td>1025</td>
            <td>Omar S. · 0109****55</td>
            <td>Free Coffee (VCH-3P7D)</td>
            <td>60</td>
            <td><span class="badge badge-void">Void</span></td>
            <td>2025-08-18 11:02</td>
            <td style="text-align:right;">
              <button class="btn" type="button" disabled>—</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <aside class="card">
      <div class="h1">Notes</div>
      <ul style="margin:8px 0 0 18px;padding:0;color:var(--muted);font-size:13px;">
        <li>Approving a redemption deducts points immediately.</li>
        <li>Voiding returns points if they were already deducted.</li>
        <li>Use the Reports section to audit redemptions weekly.</li>
      </ul>
    </aside>
  </div>
</div>
</body>
</html>