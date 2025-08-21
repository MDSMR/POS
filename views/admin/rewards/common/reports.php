<?php
// public_html/views/admin/rewards/common/reports.php
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
if(!$bootstrap_ok){ echo "<h1>Rewards – Reports</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Common · Reports";
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
      <li class="breadcrumb-item active" aria-current="page">Reports</li>
    </ol>
  </nav>

  <h1 class="mb-2">Common · Reports</h1>
  <p class="text-muted">Cross-program analytics and exports.</p>

  <?php common_tabs('reports'); ?>

  <div class="row g-3">
    <div class="col-lg-5">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Export</h5>
          <form class="row g-3" method="get" action="/controllers/admin/rewards/common/reports_export.php">
            <div class="col-12">
              <label class="form-label">Report</label>
              <select name="report" class="form-select">
                <option value="members">Members</option>
                <option value="balances">Balances (Points/Stamps/Cashback)</option>
                <option value="activity">Activity (All Programs)</option>
                <option value="coupons">Coupons</option>
                <option value="campaigns">Campaign Performance</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">From</label>
              <input type="date" name="from" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">To</label>
              <input type="date" name="to" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Format</label>
              <select name="format" class="form-select">
                <option value="csv">CSV</option>
                <option value="xlsx">Excel</option>
              </select>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Download</button>
              <a href="/controllers/admin/rewards/common/reports_email.php" class="btn btn-outline-secondary">Email Link</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-7">
      <div class="card shadow-sm">
        <div class="card-body">
          <h5 class="card-title">Quick Insights</h5>
          <div class="row g-3">
            <div class="col-md-4"><div class="p-3 bg-light rounded-3"><div class="text-muted">Members</div><div class="fs-4 fw-bold" id="r-members">—</div></div></div>
            <div class="col-md-4"><div class="p-3 bg-light rounded-3"><div class="text-muted">Avg. Points</div><div class="fs-4 fw-bold" id="r-avg-points">—</div></div></div>
            <div class="col-md-4"><div class="p-3 bg-light rounded-3"><div class="text-muted">Wallet Value</div><div class="fs-4 fw-bold" id="r-wallet">—</div></div></div>
          </div>
          <div class="mt-3">
            <small class="text-muted">Data compiled across loyalty programs for this tenant.</small>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>