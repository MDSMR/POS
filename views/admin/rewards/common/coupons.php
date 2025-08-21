<?php
// public_html/views/admin/rewards/common/coupons.php
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
if(!$bootstrap_ok){ echo "<h1>Rewards – Coupons</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Common · Coupons";
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
      <li class="breadcrumb-item active" aria-current="page">Coupons</li>
    </ol>
  </nav>

  <div class="d-flex align-items-center mb-2">
    <h1 class="mb-0 me-3">Common · Coupons</h1>
    <a href="#" class="btn btn-primary btn-sm disabled" aria-disabled="true">Create Batch</a>
  </div>
  <p class="text-muted">Issue and manage coupon codes across all programs and campaigns.</p>

  <?php common_tabs('coupons'); ?>

  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <h5 class="card-title">Generate Coupon Batch</h5>
      <form method="post" action="/controllers/admin/rewards/common/coupons_generate.php" class="row g-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
        <div class="col-md-3">
          <label class="form-label">Count</label>
          <input type="number" name="count" min="1" max="100000" class="form-control" value="100">
        </div>
        <div class="col-md-3">
          <label class="form-label">Prefix (optional)</label>
          <input type="text" name="prefix" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Length</label>
          <input type="number" name="length" min="6" max="24" class="form-control" value="10">
        </div>
        <div class="col-md-3">
          <label class="form-label">Value</label>
          <input type="number" step="0.01" name="value" class="form-control" placeholder="Amount or percentage">
        </div>
        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="type" class="form-select">
            <option value="amount">Amount</option>
            <option value="percent">Percent</option>
            <option value="free_item">Free Item</option>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label">Valid From</label>
          <input type="date" name="valid_from" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Valid To</label>
          <input type="date" name="valid_to" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Max Uses</label>
          <input type="number" name="max_uses" min="1" class="form-control" value="1">
        </div>
        <div class="col-12">
          <label class="form-label">Notes (optional)</label>
          <input type="text" name="notes" class="form-control">
        </div>
        <div class="col-12 d-flex gap-2">
          <button class="btn btn-primary">Generate</button>
          <button type="reset" class="btn btn-outline-secondary">Clear</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body">
      <h5 class="card-title">Issued Coupons</h5>
      <form class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">Search</label>
          <input type="text" name="q" class="form-control" placeholder="Code, member, or campaign">
        </div>
        <div class="col-md-2">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="">Any</option>
            <option value="unused">Unused</option>
            <option value="used">Used</option>
            <option value="expired">Expired</option>
            <option value="revoked">Revoked</option>
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
              <th>Code</th>
              <th>Member</th>
              <th>Campaign</th>
              <th>Valid</th>
              <th>Status</th>
              <th>Uses</th>
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

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>