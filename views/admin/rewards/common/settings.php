<?php
// public_html/views/admin/rewards/common/settings.php
declare(strict_types=1);

/* Bootstrap */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path=dirname(__DIR__,4).'/config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{
  $prev=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try{
    require_once $bootstrap_path;
    if(function_exists('db') && function_exists('use_backend_session')){ $bootstrap_ok=true; use_backend_session(); }
    else{ $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).'; }
  }catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally{ if($prev) set_error_handler($prev); }
}
if(!$bootstrap_ok){ echo "<h1>Rewards – Settings</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Common · Settings";
include dirname(__DIR__,3).'/partials/admin_header.php';

function common_tabs(string $active): void{
  $base='/views/admin/rewards/common';
  $tabs=[
    'members'=>['Members',"$base/members.php"],
    'tiers'=>['Tiers',"$base/tiers.php"],
    'campaigns'=>['Campaigns',"$base/campaigns.php"],
    'coupons'=>['Coupons',"$base/coupons.php"],
    'expiration'=>['Expiration',"$base/expiration.php"],
    'integrations'=>['Integrations',"$base/integrations.php"],
    'reports'=>['Reports',"$base/reports.php"],
    'settings'=>['Settings',"$base/settings.php"],
  ];
  echo '<ul class="nav nav-tabs mb-3">';
  foreach($tabs as $k=>[$label,$href]){
    $activeClass=($k===$active)?'active':'';
    echo "<li class='nav-item'><a class='nav-link $activeClass' href='$href'>$label</a></li>";
  }
  echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
      <li class="breadcrumb-item active" aria-current="page">Settings</li>
    </ol>
  </nav>

  <h1 class="mb-2">Common · Settings</h1>
  <p class="text-muted">Global settings shared across loyalty programs.</p>

  <?php common_tabs('settings'); ?>

  <form method="post" action="/controllers/admin/rewards/common/settings_save.php" class="card shadow-sm">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Default Tier</label>
          <select name="default_tier_id" class="form-select"><!-- populated --></select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Enrollment Source (default)</label>
          <select name="default_enrollment_source" class="form-select">
            <option value="pos">POS</option>
            <option value="online">Online</option>
            <option value="manual">Manual</option>
          </select>
        </div>
        <div class="col-md-4">
          <label class="form-label">Auto-Create Member On POS</label>
          <select name="auto_create_member" class="form-select">
            <option value="yes">Yes</option>
            <option value="no" selected>No</option>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Duplicate Detection</label>
          <select name="duplicate_strategy" class="form-select">
            <option value="email_or_phone" selected>Email or Phone</option>
            <option value="email_only">Email Only</option>
            <option value="phone_only">Phone Only</option>
            <option value="none">None</option>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label">Notification Channel</label>
          <select name="notify_channel" class="form-select">
            <option value="sms">SMS</option>
            <option value="email">Email</option>
            <option value="both" selected>Both</option>
            <option value="none">None</option>
          </select>
        </div>

        <div class="col-12">
          <label class="form-label">Terms & Conditions (optional)</label>
          <textarea name="terms" class="form-control" rows="4" placeholder="Shown on enrollment / loyalty pages"></textarea>
        </div>
      </div>
    </div>
    <div class="card-footer d-flex gap-2">
      <button class="btn btn-primary">Save</button>
      <button type="reset" class="btn btn-outline-secondary">Reset</button>
    </div>
  </form>
</div>

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>