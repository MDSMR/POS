<?php
// views/admin/settings.php — Setup index
declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) {
  $bootstrap_warning='Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path;
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok=true; }
  } catch (Throwable $e) { $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally { if ($prevHandler){ set_error_handler($prevHandler); } }
}

if ($bootstrap_ok) {
  try { use_backend_session(); } catch (Throwable $e){ $bootstrap_warning=$bootstrap_warning?:('Session bootstrap error: '.$e->getMessage()); }
}
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Setup · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1100px;margin:20px auto;padding:0 16px}
.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
@media (max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}}
@media (max-width:620px){.grid{grid-template-columns:1fr}}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 10px}
.tile{
  display:block;text-decoration:none;color:var(--text);
  border:1px solid var(--border);border-radius:14px;padding:16px;
  background:linear-gradient(180deg,#ffffff,#f8fbff);
  transition:transform .12s ease, box-shadow .2s ease, border-color .2s ease;
}
.tile:hover{ transform:translateY(-1px); box-shadow:0 12px 30px rgba(0,0,0,.1) }
.tile .t-title{ font-weight:800; margin-bottom:6px }
.tile .t-desc{ color:var(--muted); font-size:13px }
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
</style>
</head>
<body>

<?php
  // Highlight Setup group — we use 'settings_general' so the group lights up
  $active='settings_general';
  require __DIR__ . '/../partials/admin_nav.php';
?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= htmlspecialchars($bootstrap_warning, ENT_QUOTES) ?></div><?php endif; ?>

  <div class="h1">Setup</div>
  <div class="grid">
    <a class="tile" href="/views/admin/settings_general.php">
      <div class="t-title">General</div>
      <div class="t-desc">Restaurant profile, branding, and time zone.</div>
    </a>
    <a class="tile" href="/views/admin/settings_taxes.php">
      <div class="t-title">Taxes</div>
      <div class="t-desc">VAT and service charge configuration.</div>
    </a>
    <a class="tile" href="/views/admin/settings_payment.php">
      <div class="t-title">Payment</div>
      <div class="t-desc">Cash, card, and gateway settings.</div>
    </a>
    <a class="tile" href="/views/admin/settings_printers.php">
      <div class="t-title">Printers</div>
      <div class="t-desc">Receipt and kitchen printers.</div>
    </a>
    <a class="tile" href="/views/admin/settings_aggregators.php">
      <div class="t-title">Aggregators</div>
      <div class="t-desc">Delivery partners (API keys, menu sync, channels).</div>
    </a>
    <a class="tile" href="/views/admin/users.php">
      <div class="t-title">Users</div>
      <div class="t-desc">Staff accounts and access.</div>
    </a>
    <a class="tile" href="/views/admin/roles.php">
      <div class="t-title">Roles &amp; Permissions</div>
      <div class="t-desc">Role-based access control.</div>
    </a>
  </div>
</div>
</body>
</html>