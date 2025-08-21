<?php
// public_html/views/admin/rewards/cashback/ledger.php
declare(strict_types=1);

/* Bootstrap */ $bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path=dirname(__DIR__,4).'/config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{ $prev=set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
  try{ require_once $bootstrap_path; if(function_exists('db')&&function_exists('use_backend_session')){ $bootstrap_ok=true; use_backend_session(); }
  else{ $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';}}
  catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); } finally{ if($prev) set_error_handler($prev); } }
if(!$bootstrap_ok){ echo "<h1>Cashback – Ledger</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */ $user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];
$page_title="Rewards · Cashback · Ledger";
include dirname(__DIR__,3).'/partials/admin_header.php';

function cashback_tabs(string $active): void { $b='/views/admin/rewards/cashback';
  $t=['overview'=>['Overview',"$b/overview.php"],'rules'=>['Rules',"$b/rules.php"],'ledger'=>['Ledger',"$b/ledger.php"],'wallets'=>['Wallets',"$b/wallets.php"],'adjust'=>['Adjustments',"$b/adjustments.php"],'reports'=>['Reports',"$b/reports.php"]];
  echo '<ul class="nav nav-tabs mb-3">'; foreach($t as $k=>[$l,$h]){ $a=$k===$active?'active':''; echo "<li class='nav-item'><a class='nav-link $a' href='$h'>$l</a></li>"; } echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
    <li class="breadcrumb-item"><a href="/views/admin/rewards/cashback/overview.php">Cashback</a></li>
    <li class="breadcrumb-item active" aria-current="page">Ledger</li>
  </ol></nav>

  <h1 class="mb-2">Cashback · Ledger</h1>
  <p class="text-muted">All wallet transactions (issue, redeem, adjust).</p>

  <?php cashback_tabs('ledger'); ?>

  <div class="card shadow-sm"><div class="card-body">
    <form class="row g-3 mb-3">
      <div class="col-md-3"><label class="form-label">Member</label><input type="text" name="q" class="form-control" placeholder="Name, phone or email"></div>
      <div class="col-md-2"><label class="form-label">Type</label><select name="type" class="form-select"><option value="">Any</option><option>issue</option><option>redeem</option><option>adjust</option><option>expire</option></select></div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control"></div>
      <div class="col-md-1 d-flex align-items-end"><button class="btn btn-outline-secondary w-100">Filter</button></div>
    </form>

    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead><tr><th>Date</th><th>Member</th><th>Type</th><th class="text-end">Amount</th><th>Reason / Notes</th><th>Order</th><th>By</th></tr></thead>
        <tbody><!-- rows --></tbody>
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