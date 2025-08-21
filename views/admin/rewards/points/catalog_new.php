<?php
// public_html/views/admin/rewards/points/catalog_new.php
declare(strict_types=1);

/* Bootstrap */
$bootstrap_warning = '';
$bootstrap_ok = false;
$bootstrap_path = dirname(__DIR__, 3) . '/config/db.php'; // FIXED
if (!is_file($bootstrap_path)) {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prev = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path; // db(), use_backend_session()
    if (function_exists('db') && function_exists('use_backend_session')) {
      $bootstrap_ok = true;
      use_backend_session();
    } else {
      $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    }
  } catch (Throwable $e) {
    $bootstrap_warning = 'Bootstrap error: '.$e->getMessage();
  } finally {
    if ($prev) set_error_handler($prev);
  }
}
if (!$bootstrap_ok) { echo "<h1>Points – Catalog · New Item</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id']; $userId = (int)$user['id'];

$page_title = "Rewards · Points · New Catalog Item";
$active = 'rewards_points';
include dirname(__DIR__, 2) . '/partials/admin_header.php';

function points_tabs(string $active): void {
  $base = '/views/admin/rewards/points';
  $tabs = [
    'overview'   => ['Overview', "$base/overview.php"],
    'earn'       => ['Earn Rules', "$base/earn_rules.php"],
    'redeem'     => ['Redeem Rules', "$base/redeem_rules.php"],
    'ledger'     => ['Ledger', "$base/ledger.php"],
    'catalog'    => ['Catalog', "$base/catalog.php"],
    'redeems'    => ['Redemptions', "$base/redemptions.php"],
    'adjust'     => ['Adjustments', "$base/adjustments.php"],
    'reports'    => ['Reports', "$base/reports.php"],
  ];
  echo '<ul class="nav nav-tabs mb-3">';
  foreach ($tabs as $key => [$label, $href]) {
    $activeClass = ($key === $active) ? 'active' : '';
    echo "<li class='nav-item'><a class='nav-link $activeClass' href='$href'>$label</a></li>";
  }
  echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
      <li class="breadcrumb-item"><a href="/views/admin/rewards/points/catalog.php">Catalog</a></li>
      <li class="breadcrumb-item active" aria-current="page">New Item</li>
    </ol>
  </nav>

  <h1 class="mb-2">Points · Catalog · New Item</h1>
  <p class="text-muted">Create a new reward that members can redeem with points.</p>

  <?php points_tabs('catalog'); ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post" action="/controllers/admin/rewards/points/catalog_create.php" class="row g-3">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_points'] ?? '') ?>">

        <div class="col-md-6">
          <label class="form-label">Name</label>
          <input type="text" name="name" class="form-control" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Code (optional)</label>
          <input type="text" name="code" class="form-control">
        </div>

        <div class="col-md-3">
          <label class="form-label">Type</label>
          <select name="type" class="form-select" required>
            <option value="discount">Discount</option>
            <option value="free_item">Free Item</option>
            <option value="gift">Gift</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Points Cost</label>
          <input type="number" name="points_cost" class="form-control" min="1" required>
        </div>

        <div class="col-md-3">
          <label class="form-label">Max Redemptions</label>
          <input type="number" name="max_redemptions" class="form-control" min="0" value="0">
          <div class="form-text">0 = unlimited</div>
        </div>

        <div class="col-md-3">
          <label class="form-label">Status</label>
          <select name="status" class="form-select">
            <option value="active" selected>Active</option>
            <option value="inactive">Inactive</option>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">POS Visibility</label>
          <select name="pos_visibility" class="form-select">
            <option value="yes" selected>Visible</option>
            <option value="no">Hidden</option>
          </select>
        </div>

        <div class="col-md-6">
          <label class="form-label">Valid From</label>
          <input type="date" name="valid_from" class="form-control">
        </div>

        <div class="col-md-6">
          <label class="form-label">Valid To</label>
          <input type="date" name="valid_to" class="form-control">
        </div>

        <div class="col-12">
          <label class="form-label">Description (optional)</label>
          <textarea name="description" class="form-control" rows="3"></textarea>
        </div>

        <div class="col-12 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Create</button>
          <a href="/views/admin/rewards/points/catalog.php" class="btn btn-outline-secondary">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php include dirname(__DIR__, 2) . '/partials/admin_footer.php'; ?>