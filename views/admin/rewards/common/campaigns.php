<?php
// public_html/views/admin/rewards/common/campaigns.php
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
if(!$bootstrap_ok){ echo "<h1>Rewards – Campaigns</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Common · Campaigns";
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
      <li class="breadcrumb-item active" aria-current="page">Campaigns</li>
    </ol>
  </nav>

  <div class="d-flex align-items-center mb-2">
    <h1 class="mb-0 me-3">Common · Campaigns</h1>
    <a href="#" class="btn btn-primary btn-sm disabled" aria-disabled="true">New Campaign</a>
  </div>
  <p class="text-muted">Create time-bound promotions that affect earn rates, redemptions, or issue coupons.</p>

  <?php common_tabs('campaigns'); ?>

  <div class="row">
    <div class="col-lg-5">
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h5 class="card-title">Create / Edit Campaign</h5>
          <form method="post" action="/controllers/admin/rewards/common/campaign_save.php" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
            <input type="hidden" name="id" value="">
            <div class="col-12">
              <label class="form-label">Name</label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Start</label>
              <input type="datetime-local" name="start_at" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">End</label>
              <input type="datetime-local" name="end_at" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Program</label>
              <select name="program" class="form-select">
                <option value="points">Points</option>
                <option value="stamp">Stamp</option>
                <option value="cashback">Cashback</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Action</label>
              <select name="action" class="form-select">
                <option value="boost_earn">Boost Earn</option>
                <option value="discount_redeem">Discount Redeem</option>
                <option value="issue_coupon">Issue Coupon</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Parameters (JSON)</label>
              <textarea name="params_json" class="form-control" rows="4" placeholder='{"multiplier":2,"category_id":null}'></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="draft" selected>Draft</option>
                <option value="scheduled">Scheduled</option>
                <option value="active">Active</option>
                <option value="ended">Ended</option>
              </select>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Save</button>
              <button type="reset" class="btn btn-outline-secondary">Clear</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Campaigns</h5>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Window</th>
                  <th>Program</th>
                  <th>Action</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody><!-- Populated by controller --></tbody>
            </table>
          </div>
          <nav>
            <ul class="pagination pagination-sm mb-0">
              <li class="page-item disabled"><span class="page-link">Prev</span></li>
              <li class="page-item active"><span class="page-link">1</span></li>
              <li class="page-item"><a class="page-link" href="#">2</a></li>
              <li class="page-item"><a class="page-link" href="#">Next</a></li>
            </ul>
          </nav>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>