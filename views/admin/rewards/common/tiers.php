<?php
// public_html/views/admin/rewards/common/tiers.php
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
if(!$bootstrap_ok){ echo "<h1>Rewards – Tiers</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Common · Tiers";
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
      <li class="breadcrumb-item active" aria-current="page">Tiers</li>
    </ol>
  </nav>

  <div class="d-flex align-items-center mb-2">
    <h1 class="mb-0 me-3">Common · Tiers</h1>
    <a href="#" class="btn btn-primary btn-sm disabled" aria-disabled="true">New Tier</a>
  </div>
  <p class="text-muted">Define loyalty tiers, thresholds, and benefits.</p>

  <?php common_tabs('tiers'); ?>

  <div class="row">
    <div class="col-lg-5">
      <div class="card shadow-sm mb-4">
        <div class="card-body">
          <h5 class="card-title">Create / Edit Tier</h5>
          <form method="post" action="/controllers/admin/rewards/common/tier_save.php" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
            <input type="hidden" name="id" value="">
            <div class="col-md-6">
              <label class="form-label">Name</label>
              <input type="text" name="name" class="form-control" required>
            </div>
            <div class="col-md-6">
              <label class="form-label">Code</label>
              <input type="text" name="code" class="form-control">
            </div>
            <div class="col-md-6">
              <label class="form-label">Threshold (Points)</label>
              <input type="number" name="threshold_points" min="0" class="form-control" value="0">
            </div>
            <div class="col-md-6">
              <label class="form-label">Threshold (Spend)</label>
              <input type="number" name="threshold_spend" min="0" step="0.01" class="form-control" value="0">
            </div>
            <div class="col-12">
              <label class="form-label">Benefits (JSON)</label>
              <textarea name="benefits_json" class="form-control" rows="5" placeholder='{"earn_rate_multiplier":1.2,"birthday_bonus":100}'></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="active" selected>Active</option>
                <option value="inactive">Inactive</option>
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
          <h5 class="card-title">Tiers</h5>
          <div class="table-responsive">
            <table class="table table-sm table-striped align-middle">
              <thead>
                <tr>
                  <th>Name</th>
                  <th>Threshold (Pts/Spend)</th>
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

  <?php common_tabs('tiers'); /* tabs at bottom optional; keeps context when scrolling */ ?>
</div>

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>