<?php
// public_html/views/admin/dashboard.php — Safe admin dashboard using global header + defensive nav include
declare(strict_types=1);

// ===== Optional runtime debug switches (use ?debug=1 in the URL) =====
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  // Only force display errors when explicitly debugging via the query param
  ini_set('display_errors', '1');
  ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

/* ===== Bootstrap ===== */
$bootstrap_warning = '';
$bootstrap_ok = false;
$bootstrap_path = __DIR__ . '/../../config/db.php';

if (!is_file($bootstrap_path)) {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
  });
  try {
    require_once $bootstrap_path; // expects db(), use_backend_session()
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

if ($bootstrap_ok) {
  try {
    use_backend_session();
  } catch (Throwable $e) {
    $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: ' . $e->getMessage());
  }
}

/* ===== Auth ===== */
$user = $_SESSION['user'] ?? null;
if (!$user) {
  header('Location: /views/auth/login.php');
  exit;
}
$tenantId = (int)($user['tenant_id'] ?? 0);

/* ===== KPIs (tenant-scoped; guarded) ===== */
$db_ok  = false;
$db_msg = '';
$stats  = [
  'users'       => null, // active users in this tenant
  'open_orders' => null, // today
];

if ($bootstrap_ok) {
  try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Users in this tenant (exclude disabled)
    try {
      $st = $pdo->prepare("SELECT COUNT(*) AS c FROM users WHERE tenant_id = :t AND (disabled_at IS NULL)");
      $st->execute([':t' => $tenantId]);
      $row = $st->fetch();
      $stats['users'] = isset($row['c']) ? (int)$row['c'] : null;
    } catch (Throwable $e) {
      if ($DEBUG) { $db_msg .= "[Users KPI] {$e->getMessage()}\n"; }
    }

    // Open/active orders created today (best-effort: compatible with our statuses)
    try {
      $st = $pdo->prepare("
        SELECT COUNT(*) AS c
        FROM orders
        WHERE tenant_id = :t
          AND DATE(created_at) = CURDATE()
          AND (status IN ('open','held','sent','preparing','ready','served') OR status IS NULL)
      ");
      $st->execute([':t' => $tenantId]);
      $row = $st->fetch();
      $stats['open_orders'] = isset($row['c']) ? (int)$row['c'] : null;
    } catch (Throwable $e) {
      if ($DEBUG) { $db_msg .= "[Orders KPI] {$e->getMessage()}\n"; }
    }

    $db_ok = true;
  } catch (Throwable $e) {
    $db_ok  = false;
    $db_msg = $db_msg ?: $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Dashboard · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
:root{--bg:#f7f8fa; --card:#ffffff; --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1100px;margin:20px auto;padding:0 16px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
@media (max-width:920px){.kpis{grid-template-columns:repeat(2,1fr)}}
.kpi{border:1px solid var(--border);border-radius:12px;padding:14px}
.kpi .title{color:var(--muted);font-size:12px;margin-bottom:6px}
.kpi .value{font-size:22px;font-weight:800}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.error{background:#fee2e2;border:1px solid #fecaca;color:#7f1d1d;padding:10px;border-radius:10px;margin:10px 0}
.small{color:var(--muted);font-size:12px}
.link{color:var(--primary);text-decoration:none}
</style>
</head>
<body>

<?php
  // ===== Defensive include of the navigation =====
  $active = 'dashboard';
  $__nav_file = __DIR__ . '/../partials/admin_nav.php';
  if ($DEBUG) {
    echo "<!-- will-include: {$__nav_file} -->\n";
  }
  ob_start();
  try {
    require $__nav_file;
  } catch (Throwable $e) {
    ob_end_clean();
    // Show a visible error block if the nav fatals, so you don’t get a blank 500 page.
    echo "<pre style='color:#b91c1c;background:#fee2e2;padding:10px;border-radius:8px'>".
         "NAV INCLUDE ERROR:\n".$e->getMessage()."\n\n".
         $e->getFile().":".$e->getLine()."</pre>";
    exit;
  }
  $__nav_html = ob_get_clean();
  if ($DEBUG) {
    echo "<!-- nav-included-ok -->\n";
  }
  echo $__nav_html;
?>

<div class="container">
  <?php if ($bootstrap_warning): ?>
    <div class="notice">
      <?= htmlspecialchars($bootstrap_warning, ENT_QUOTES, 'UTF-8') ?>
      <?php if ($DEBUG): ?>
        <div class="small" style="margin-top:6px">Open <code>/views/auth/login.php?debug=1</code> for more details.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if (!$db_ok): ?>
    <div class="error">
      There was a database error while loading dashboard data.
      <?php if ($DEBUG && $db_msg): ?>
        <div class="small" style="margin-top:6px; white-space:pre-wrap">
          DEBUG: <?= htmlspecialchars($db_msg, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="kpis">
      <div class="kpi">
        <div class="title">Users</div>
        <div class="value"><?= $stats['users'] !== null ? (int)$stats['users'] : '—' ?></div>
      </div>
      <div class="kpi">
        <div class="title">Open Orders Today</div>
        <div class="value"><?= $stats['open_orders'] !== null ? (int)$stats['open_orders'] : '—' ?></div>
      </div>
      <div class="kpi">
        <div class="title">Status</div>
        <div class="value"><?= $db_ok ? 'DB Connected' : 'DB Issue' ?></div>
      </div>
      <div class="kpi">
        <div class="title">Role</div>
        <div class="value"><?= htmlspecialchars((string)($user['role_key'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
  </div>

  <div class="card" style="display:flex;align-items:center;justify-content:space-between">
    <div>
      <div style="font-weight:700">Welcome</div>
      <div class="small">Use the navigation above to access features.</div>
    </div>
    <div>
      <a class="link" href="/pos/login.php">Go to POS</a>
      <?php if ($DEBUG): ?>
        <span class="small" style="margin-left:10px">• <a class="link" href="?">Disable debug</a></span>
      <?php else: ?>
        <span class="small" style="margin-left:10px">• <a class="link" href="?debug=1">Enable debug</a></span>
      <?php endif; ?>
    </div>
  </div>
</div>

</body>
</html>