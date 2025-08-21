<?php
// public_html/views/admin/rewards/points/earn_rules.php
declare(strict_types=1);

/* Bootstrap */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path = dirname(__DIR__, 3) . '/config/db.php'; // FIXED
if (!is_file($bootstrap_path)) { $bootstrap_warning='Configuration file not found: /config/db.php'; }
else {
  $prev=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try { require_once $bootstrap_path;
    if (function_exists('db') && function_exists('use_backend_session')) { $bootstrap_ok=true; use_backend_session(); }
    else { $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).'; }
  } catch (Throwable $e) { $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally { if ($prev) set_error_handler($prev); }
}
if(!$bootstrap_ok){ echo "<h1>Points – Earn Rules</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Points · Earn Rules";
$active = 'rewards_points';
include dirname(__DIR__, 2) . '/partials/admin_header.php';

function points_tabs(string $active): void {
  $base = '/views/admin/rewards/points';
  $tabs = [
    'overview'=>['Overview',"$base/overview.php"],
    'earn'=>['Earn Rules',"$base/earn_rules.php"],
    'redeem'=>['Redeem Rules',"$base/redeem_rules.php"],
    'ledger'=>['Ledger',"$base/ledger.php"],
    'catalog'=>['Catalog',"$base/catalog.php"],
    'redeems'=>['Redemptions',"$base/redemptions.php"],
    'adjust'=>['Adjustments',"$base/adjustments.php"],
    'reports'=>['Reports',"$base/reports.php"],
  ];
  echo '<ul class="nav nav-tabs mb-3">';
  foreach ($tabs as $key => [$label,$href]) {
    $activeClass = ($key===$active)?'active':'';
    echo "<li class='nav-item'><a class='nav-link $activeClass' href='$href'>$label</a></li>";
  }
  echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
      <li class="breadcrumb-item"><a href="/views/admin/rewards/points/overview.php">Points</a></li>
      <li class="breadcrumb-item active" aria-current="page">Earn Rules</li>
    </ol>
  </nav>

  <h1 class="mb-2">Points · Earn Rules</h1>
  <p class="text-muted">Define how customers earn points (by spend, item/category, time, channel, etc.).</p>

  <?php points_tabs('earn'); ?>

  <form id="earn-rules-form" method="post" action="/controllers/admin/rewards/points/rules_save.php">
    <input type="hidden" name="type" value="earn">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_points'] ?? '') ?>">
    <div class="card shadow-sm">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center">
          <h5 class="card-title mb-0">Rules (JSON)</h5>
          <div><button type="submit" class="btn btn-primary btn-sm">Save</button></div>
        </div>
        <p class="text-muted mt-2 mb-3">Stored in <code>loyalty_programs.points->earn_rules</code>.</p>
        <textarea name="rules_json" class="form-control" rows="16" placeholder='[ { "type": "spend", "rate": 1, "per": 1.00 } ]'></textarea>
      </div>
    </div>
  </form>
</div>

<?php include dirname(__DIR__, 2) . '/partials/admin_footer.php'; ?>