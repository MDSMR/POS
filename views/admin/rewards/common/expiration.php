<?php
// public_html/views/admin/rewards/common/expiration.php
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
if(!$bootstrap_ok){ echo "<h1>Rewards – Expiration</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Common · Expiration";
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
      <li class="breadcrumb-item active" aria-current="page">Expiration</li>
    </ol>
  </nav>

  <h1 class="mb-2">Common · Expiration</h1>
  <p class="text-muted">Configure expiration policies and review upcoming expiries.</p>

  <?php common_tabs('expiration'); ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h5 class="card-title">Policies</h5>
      <form method="post" action="/controllers/admin/rewards/common/expiration_save.php" class="row g-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
        <div class="col-md-3">
          <label class="form-label">Points Expire After (months)</label>
          <input type="number" name="points_months" min="0" class="form-control" value="0">
          <div class="form-text">0 = never expire</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">Stamp Card Validity (months)</label>
          <input type="number" name="stamps_months" min="0" class="form-control" value="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Cashback Expire After (months)</label>
          <input type="number" name="cashback_months" min="0" class="form-control" value="0">
        </div>
        <div class="col-md-3">
          <label class="form-label">Grace Period (days)</label>
          <input type="number" name="grace_days" min="0" class="form-control" value="0">
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Save</button>
          <button type="reset" class="btn btn-outline-secondary">Reset</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <h5 class="card-title">Upcoming Expirations</h5>
      <form class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">Within</label>
          <select name="within" class="form-select">
            <option value="30">30 days</option>
            <option value="60">60 days</option>
            <option value="90" selected>90 days</option>
          </select>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button class="btn btn-outline-secondary w-100">Filter</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Member</th>
              <th>Program</th>
              <th>Amount</th>
              <th>Expires On</th>
              <th>Contact</th>
              <th></th>
            </tr>
          </thead>
          <tbody><!-- Populated by controller --></tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>