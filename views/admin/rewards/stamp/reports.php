<?php
// public_html/views/admin/rewards/stamp/reports.php
declare(strict_types=1);

/* Bootstrap */ $bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path=dirname(__DIR__,4).'/config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{ $prev=set_error_handler(function($s,$m,$f,$l){throw new ErrorException($m,0,$s,$f,$l);});
  try{ require_once $bootstrap_path; if(function_exists('db')&&function_exists('use_backend_session')){ $bootstrap_ok=true; use_backend_session(); }
  else{ $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';}}
  catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); } finally{ if($prev) set_error_handler($prev); } }
if(!$bootstrap_ok){ echo "<h1>Stamp – Reports</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */ $user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];
$page_title="Rewards · Stamp · Reports";
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
    <li class="breadcrumb-item active" aria-current="page">Reports</li>
  </ol></nav>

  <h1 class="mb-2">Stamp · Reports</h1>
  <p class="text-muted">Downloads and KPIs for stamp program.</p>

  <?php stamp_tabs('reports'); ?>

  <div class="card shadow-sm mb-4"><div class="card-body">
    <h5 class="card-title">Quick Insights</h5>
    <div class="row g-3">
      <div class="col-md-3"><div class="p-3 bg-light rounded-3 h-100"><div class="text-muted">Active Cards</div><div class="fs-4 fw-bold">—</div></div></div>
      <div class="col-md-3"><div class="p-3 bg-light rounded-3 h-100"><div class="text-muted">Completed (30d)</div><div class="fs-4 fw-bold">—</div></div></div>
      <div class="col-md-3"><div class="p-3 bg-light rounded-3 h-100"><div class="text-muted">Rewards Issued (30d)</div><div class="fs-4 fw-bold">—</div></div></div>
      <div class="col-md-3"><div class="p-3 bg-light rounded-3 h-100"><div class="text-muted">Expiry (next 30d)</div><div class="fs-4 fw-bold">—</div></div></div>
    </div>
  </div></div>

  <div class="card shadow-sm"><div class="card-body">
    <h5 class="card-title">Export</h5>
    <form class="row g-3" method="get" action="/controllers/admin/rewards/stamp/reports_export.php">
      <div class="col-md-3"><label class="form-label">Report</label>
        <select name="report" class="form-select"><option value="cards">Cards</option><option value="issued">Issued Rewards</option><option value="adjustments">Adjustments</option></select></div>
      <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control"></div>
      <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control"></div>
      <div class="col-md-3"><label class="form-label">Format</label><select name="format" class="form-select"><option value="csv">CSV</option><option value="xlsx">Excel</option></select></div>
      <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Download</button></div>
    </form>
  </div></div>
</div>
<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>