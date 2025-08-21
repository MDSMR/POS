<?php
// public_html/views/admin/rewards/points/ledger.php
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
if(!$bootstrap_ok){ echo "<h1>Points – Ledger</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Points · Ledger";
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
      <li class="breadcrumb-item active" aria-current="page">Ledger</li>
    </ol>
  </nav>

  <h1 class="mb-2">Points · Ledger</h1>
  <p class="text-muted">All points transactions (earn, redeem, adjustment), newest first.</p>

  <?php points_tabs('ledger'); ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form class="row g-3 mb-3">
        <div class="col-md-3">
          <label class="form-label">Member</label>
          <input type="text" name="q" class="form-control" placeholder="Name, phone or email">
        </div>
        <div class="col-md-2">
          <label class="form-label">Type</label>
          <select name="type" class="form-select">
            <option value="">Any</option>
            <option value="earn">Earn</option>
            <option value="redeem">Redeem</option>
            <option value="adjust">Adjustment</option>
            <option value="expire">Expire</option>
            <option value="revoke">Revoke</option>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">From</label>
          <input type="date" name="from" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">To</label>
          <input type="date" name="to" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Order #</label>
          <input type="text" name="order_code" class="form-control" placeholder="Optional">
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button class="btn btn-outline-secondary w-100">Filter</button>
        </div>
      </form>

      <div class="table-responsive">
        <table class="table table-sm table-striped align-middle">
          <thead>
            <tr>
              <th>Date</th>
              <th>Member</th>
              <th>Type</th>
              <th class="text-end">Points</th>
              <th>Reason / Notes</th>
              <th>Order</th>
              <th>By</th>
            </tr>
          </thead>
          <tbody>
            <!-- Rows populated by controller (pagination ready) -->
          </tbody>
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

<?php include dirname(__DIR__, 2) . '/partials/admin_footer.php'; ?>