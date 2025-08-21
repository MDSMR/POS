<?php
// public_html/views/admin/rewards/common/member_view.php
declare(strict_types=1);

/* Bootstrap */
$bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path = dirname(__DIR__, 4) . '/config/db.php';
if (!is_file($bootstrap_path)) { $bootstrap_warning='Configuration file not found: /config/db.php'; }
else {
  $prev=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path; // db(), use_backend_session()
    if (function_exists('db') && function_exists('use_backend_session')) { $bootstrap_ok=true; use_backend_session(); }
    else { $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).'; }
  } catch (Throwable $e) { $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally { if ($prev) set_error_handler($prev); }
}
if(!$bootstrap_ok){ echo "<h1>Rewards – Member</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null;
if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id'];
$userId=(int)$user['id'];

/* Input */
$member_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($member_id <= 0) { header('Location: /views/admin/rewards/common/members.php'); exit; }

$page_title="Rewards · Common · Member";

include dirname(__DIR__,3).'/partials/admin_header.php';

function common_tabs(string $active): void {
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
      <li class="breadcrumb-item"><a href="/views/admin/rewards/common/members.php">Members</a></li>
      <li class="breadcrumb-item active" aria-current="page">Member</li>
    </ol>
  </nav>

  <div class="d-flex align-items-center mb-2">
    <h1 class="mb-0 me-3">Member · #<?= htmlspecialchars((string)$member_id) ?></h1>
    <a href="/views/admin/rewards/common/members.php" class="btn btn-outline-secondary btn-sm">Back to Members</a>
  </div>
  <p class="text-muted">Member profile, balances, and history across Points, Stamps, and Cashback.</p>

  <?php common_tabs('members'); ?>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h5 class="card-title">Profile</h5>
          <dl class="row mb-0">
            <dt class="col-5">Name</dt><dd class="col-7" id="m-name">—</dd>
            <dt class="col-5">Phone</dt><dd class="col-7" id="m-phone">—</dd>
            <dt class="col-5">Email</dt><dd class="col-7" id="m-email">—</dd>
            <dt class="col-5">Status</dt><dd class="col-7" id="m-status">—</dd>
            <dt class="col-5">Tier</dt><dd class="col-7" id="m-tier">—</dd>
            <dt class="col-5">Joined</dt><dd class="col-7" id="m-joined">—</dd>
          </dl>
          <hr>
          <form class="row g-2" method="post" action="/controllers/admin/rewards/common/member_update.php">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
            <input type="hidden" name="id" value="<?= $member_id ?>">
            <div class="col-12">
              <label class="form-label">Tier</label>
              <select name="tier_id" class="form-select">
                <!-- Populated by controller -->
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Status</label>
              <select name="status" class="form-select">
                <option value="active">Active</option>
                <option value="blocked">Blocked</option>
              </select>
            </div>
            <div class="col-12 d-flex gap-2 mt-2">
              <button class="btn btn-primary">Save</button>
              <a href="/controllers/admin/rewards/common/member_delete.php?id=<?= $member_id ?>" class="btn btn-outline-danger">Delete</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-8">
      <div class="row g-3">
        <div class="col-md-4">
          <div class="p-3 bg-light rounded-3 h-100">
            <div class="text-muted">Points Balance</div>
            <div class="fs-4 fw-bold" id="m-points">—</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="p-3 bg-light rounded-3 h-100">
            <div class="text-muted">Active Stamps</div>
            <div class="fs-4 fw-bold" id="m-stamps">—</div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="p-3 bg-light rounded-3 h-100">
            <div class="text-muted">Cashback Wallet</div>
            <div class="fs-4 fw-bold" id="m-cashback">—</div>
          </div>
        </div>
      </div>

      <div class="card shadow-sm mt-3">
        <div class="card-body">
          <ul class="nav nav-pills mb-3">
            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tab-ledger">Ledger</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-orders">Orders</a></li>
            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tab-coupons">Coupons</a></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="tab-ledger">
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Program</th>
                      <th>Type</th>
                      <th class="text-end">Amount</th>
                      <th>Notes</th>
                      <th>By</th>
                    </tr>
                  </thead>
                  <tbody><!-- Populated by controller --></tbody>
                </table>
              </div>
            </div>
            <div class="tab-pane fade" id="tab-orders">
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                  <thead>
                    <tr>
                      <th>Date</th>
                      <th>Order</th>
                      <th>Channel</th>
                      <th class="text-end">Total</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody><!-- Populated by controller --></tbody>
                </table>
              </div>
            </div>
            <div class="tab-pane fade" id="tab-coupons">
              <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                  <thead>
                    <tr>
                      <th>Coupon</th>
                      <th>Issued</th>
                      <th>Expires</th>
                      <th>Status</th>
                      <th></th>
                    </tr>
                  </thead>
                  <tbody><!-- Populated by controller --></tbody>
                </table>
              </div>
            </div>
          </div>

          <nav class="mt-3">
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