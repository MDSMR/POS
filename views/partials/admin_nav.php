<?php
declare(strict_types=1);

/* Ensure expected vars exist */
$active = isset($active) && is_string($active) ? $active : '';
$user   = $_SESSION['user'] ?? [];

/* Auto-detect by current path (fallback if $active not passed) */
$script = (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
$uri    = (string)($_SERVER['REQUEST_URI'] ?? $script);

/* Groups by $active (legacy support) */
$__isCatalog = in_array($active, ['catalog','products','categories','modifiers'], true);

/* Expanded Rewards set to cover all new sections */
$__rewardsKeys = [
  'rewards','rewards_points','rewards_stamp','rewards_cashback','rewards_common',
  'customers','rewards_customers','rewards_programs','rewards_rules','rewards_vouchers',
  'rewards_members','rewards_tiers','rewards_campaigns','rewards_coupons','rewards_expiration',
  'rewards_integrations','rewards_reports','rewards_settings'
];
$__isRewards = in_array($active, $__rewardsKeys, true);

/* Other groups unchanged */
$__isReports = in_array($active, ['reports','reports_sales','reports_orders','reports_inventory','reports_staff','reports_rewards'], true);
$__isSetup   = in_array($active, ['settings','settings_general','settings_taxes','settings_payment','settings_printers','settings_aggregators','users','roles'], true);

/* Path-based fallback highlighting (covers all /views/admin/rewards/* pages) */
if (!$__isRewards && (str_contains($script, '/views/admin/rewards/') || str_contains($uri, '/views/admin/rewards/'))) {
  $__isRewards = true;
}
if (!$__isCatalog && (str_contains($script, '/views/admin/products') || str_contains($script, '/views/admin/categories') || str_contains($script, '/views/admin/modifiers'))) {
  $__isCatalog = true;
}
?>
<style>
:root{
  --text:#111827; --muted:#6b7280; --primary:#2563eb; --border:#e5e7eb;
  --bg:#ffffff; --hover:#f3f4f6; --brand:#e11d48; --pill:#eef2ff;
}
/* Optional brand font */
@font-face{
  font-family:"Moonshine";
  src: url("/assets/fonts/Moonshine.woff2") format("woff2"),
       url("/assets/fonts/Moonshine.woff") format("woff");
  font-display: swap;
}
.moonshine{ font-family:"Moonshine", cursive; font-weight:400 }
/* Header shell */
.admin-header{
  position:sticky; top:0; z-index:50;
  background:#ffffffd9; backdrop-filter:blur(8px);
  border-bottom:1px solid var(--border);
}
.admin-inner{
  max-width:1200px; margin:0 auto; padding:10px 16px;
  display:flex; align-items:center; gap:16px; justify-content:space-between;
}
/* Brand (left) */
.brand{ display:flex; align-items:center; gap:10px; min-width:160px; }
.brand .logo{ font-size:28px; line-height:1; color:var(--brand) }
/* Nav (center) */
.nav{
  display:flex; align-items:center; gap:8px; flex:1; justify-content:center;
  overflow-x:auto; white-space:nowrap; scrollbar-width:none;
}
.nav::-webkit-scrollbar{ display:none }
.nav a{
  border:1px solid transparent; background:transparent; color:#374151;
  font-size:14px; padding:8px 12px; border-radius:10px; cursor:pointer;
  text-decoration:none;
  transition:background .15s ease, box-shadow .15s ease, color .15s ease;
}
.nav a:hover{ background:var(--hover) }
.nav .active{
  color:#111827; font-weight:700; background:var(--pill);
  border-color:#e5e7eb; box-shadow:0 2px 8px rgba(0,0,0,.06);
}
/* Right side */
.right{ display:flex; align-items:center; gap:10px; }
.badge{
  font-size:12px; color:#065f46; background:#d1fae5; border:1px solid #a7f3d0;
  padding:2px 8px; border-radius:999px;
}
.user-chip{
  display:flex; align-items:center; gap:8px; padding:6px 10px;
  border:1px solid var(--border); border-radius:999px; background:#fff;
}
.user-dot{ width:8px; height:8px; border-radius:50%; background:#34d399; }
.user-name{ max-width:180px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; color:#111827 }
/* Mobile */
@media (max-width:900px){ .nav{ justify-content:flex-start } }
</style>

<header class="admin-header">
  <div class="admin-inner">
    <!-- Brand -->
    <div class="brand">
      <div class="logo moonshine">Smorll</div>
    </div>

    <!-- Primary nav -->
    <nav class="nav" aria-label="Primary">
      <a href="/views/admin/dashboard.php"
         class="<?= $active==='dashboard' ? 'active' : '' ?>">Dashboard</a>

      <a href="/views/admin/catalog.php"
         class="<?= $__isCatalog ? 'active' : '' ?>">Catalog</a>

      <a href="/views/admin/inventory.php"
         class="<?= $active==='inventory' ? 'active' : '' ?>">Inventory</a>

      <a href="/views/admin/orders/index.php"
         class="<?= $active==='orders' ? 'active' : '' ?>">Orders</a>

      <!-- UPDATED: Rewards now points to the Rewards landing page and highlights for any /rewards/* view -->
      <a href="/views/admin/rewards/index.php"
         class="<?= $__isRewards ? 'active' : '' ?>">Rewards</a>

      <a href="/views/admin/reports.php"
         class="<?= $__isReports ? 'active' : '' ?>">Reports</a>

      <a href="/views/admin/settings.php"
         class="<?= $__isSetup ? 'active' : '' ?>">Setup</a>
    </nav>

    <!-- Right: status + user -->
    <div class="right">
      <span class="badge">Online</span>
      <div class="user-chip" title="<?= htmlspecialchars(($user['email'] ?? ''), ENT_QUOTES) ?>">
        <span class="user-dot"></span>
        <span class="user-name"><?= htmlspecialchars($user['name'] ?? $user['email'] ?? 'Admin', ENT_QUOTES) ?></span>
      </div>
    </div>
  </div>
</header>