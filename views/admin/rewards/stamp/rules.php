<?php
// public_html/views/admin/rewards/stamp/rules.php
declare(strict_types=1);

/* Bootstrap */ $bootstrap_warning=''; $bootstrap_ok=false;
$bootstrap_path = dirname(__DIR__,4).'/config/db.php';
if(!is_file($bootstrap_path)){ $bootstrap_warning='Configuration file not found: /config/db.php'; }
else{ $prev=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l);});
  try{ require_once $bootstrap_path; if(function_exists('db')&&function_exists('use_backend_session')){ $bootstrap_ok=true; use_backend_session(); }
  else{ $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';}}
  catch(Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); } finally{ if($prev) set_error_handler($prev); } }
if(!$bootstrap_ok){ echo "<h1>Stamp – Rules</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */ $user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];
$page_title="Rewards · Stamp · Rules";
include dirname(__DIR__,3).'/partials/admin_header.php';

function stamp_tabs(string $active): void {
  $base='/views/admin/rewards/stamp';
  $tabs=['overview'=>['Overview',"$base/overview.php"],'rules'=>['Rules',"$base/rules.php"],'cards'=>['Cards',"$base/cards.php"],'issued'=>['Issued',"$base/issued.php"],'adjust'=>['Adjustments',"$base/adjustments.php"],'reports'=>['Reports',"$base/reports.php"]];
  echo '<ul class="nav nav-tabs mb-3">'; foreach($tabs as $k=>[$l,$h]){ $a=$k===$active?'active':''; echo "<li class='nav-item'><a class='nav-link $a' href='$h'>$l</a></li>"; } echo '</ul>';
}
?>
<div class="container mt-4">
  <nav aria-label="breadcrumb"><ol class="breadcrumb">
    <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
    <li class="breadcrumb-item"><a href="/views/admin/rewards/stamp/overview.php">Stamp</a></li>
    <li class="breadcrumb-item active" aria-current="page">Rules</li>
  </ol></nav>

  <h1 class="mb-2">Stamp · Rules</h1>
  <p class="text-muted">Configure card size, qualifying actions, and reward issuance.</p>

  <?php stamp_tabs('rules'); ?>

  <form method="post" action="/controllers/admin/rewards/stamp/rules_save.php">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_stamp']??'') ?>">
    <div class="card shadow-sm"><div class="card-body">
      <div class="d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Rules (JSON)</h5>
        <button class="btn btn-primary btn-sm">Save</button>
      </div>
      <p class="text-muted mt-2 mb-3">Stored in <code>loyalty_programs.stamp->rules</code>.</p>
      <textarea name="rules_json" class="form-control" rows="16" placeholder='{ "stamps_per_card": 10, "qualifier": {"type":"order_total","min":5.00}, "reward":{"type":"free_item","code":"FREE_COFFEE"} }'></textarea>
    </div></div>
  </form>
</div>
<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>