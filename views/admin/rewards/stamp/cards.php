<?php
// public_html/views/admin/rewards/stamp/cards.php
declare(strict_types=1);

/* Bootstrap */ $bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path=dirname(__DIR__,4).'/config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{ $prev=set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
  try{ require_once $bootstrap_path; if(function_exists('db')&&function_exists('use_backend_session')){ $bootstrap_ok=true; use_backend_session(); }
  else{ $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).'; } }
  catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); } finally{ if($prev) set_error_handler($prev); } }
if(!$bootstrap_ok){ echo "<h1>Stamp – Cards</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */ $user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];
$page_title="Rewards · Stamp · Cards";
include dirname(__DIR__,3).'/partials/admin_header.php';

function stamp_tabs(string $active): void { $b='/views/admin/rewards/stamp';
  $t=['overview'=>['Overview',"$b/overview.php"],'rules'=>['Rules',"$b/rules.php"],'cards'=>['Cards',"$b/cards.php"],'issued'=>['Issued',"$b/issued.php"],'adjust'=>['Adjustments',"$b/adjustments.php"],'reports'=>['Reports',"$b/reports.php"]];
  echo '<ul class="nav nav-tabs mb-3">'; foreach($t as $k=>[$l,$h]){ $a=$k===$active?'active':''; echo "<li class='nav-item'><a class='nav-link $a' href='$h'>$l</a></li>"; } echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
    <li class="breadcrumb-item"><a href="/views/admin/rewards/stamp/overview.php">Stamp</a></li>
    <li class="breadcrumb-item active" aria-current="page">Cards</li>
  </ol></nav>

  <h1 class="mb-2">Stamp · Cards</h1>
  <p class="text-muted">Manage digital stamp cards per member.</p>

  <?php stamp_tabs('cards'); ?>

  <div class="card shadow-sm"><div class="card-body">
    <form class="row g-3 mb-3">
      <div class="col-md-3"><label class="form-label">Member</label><input type="text" name="q" class="form-control" placeholder="Name, phone or email"></div>
      <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><option value="">Any</option><option>Active</option><option>Completed</option><option>Expired</option></select></div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control"></div>
      <div class="col-md-1 d-flex align-items-end"><button class="btn btn-outline-secondary w-100">Filter</button></div>
      <div class="col-md-2 d-flex align-items-end"><a href="/controllers/admin/rewards/stamp/card_create.php" class="btn btn-primary w-100">New Card</a></div>
    </form>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead><tr><th>Member</th><th>Card Code</th><th class="text-end">Stamps</th><th>Status</th><th>Valid</th><th></th></tr></thead>
        <tbody><!-- populated by controller --></tbody>
      </table>
    </div>

    <nav><ul class="pagination pagination-sm mb-0">
      <li class="page-item disabled"><span class="page-link">Prev</span></li>
      <li class="page-item active"><span class="page-link">1</span></li>
      <li class="page-item"><a class="page-link" href="#">2</a></li>
      <li class="page-item"><a class="page-link" href="#">Next</a></li>
    </ul></nav>
  </div></div>
</div>
<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>