<?php
// public_html/views/admin/rewards/common/integrations.php
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
if(!$bootstrap_ok){ echo "<h1>Rewards – Integrations</h1><div style='color:red;'>$bootstrap_warning</div>"; exit; }

/* Auth */
$user=$_SESSION['user']??null; if(!$user){ header('Location: /views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id']; $userId=(int)$user['id'];

$page_title="Rewards · Common · Integrations";
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
      <li class="breadcrumb-item active" aria-current="page">Integrations</li>
    </ol>
  </nav>

  <h1 class="mb-2">Common · Integrations</h1>
  <p class="text-muted">Connect third-party systems (SMS, Email, Payment, CRM) for loyalty operations.</p>

  <?php common_tabs('integrations'); ?>

  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h5 class="card-title">SMS Gateway</h5>
          <form method="post" action="/controllers/admin/rewards/common/integration_save.php" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
            <input type="hidden" name="integration" value="sms">
            <div class="col-12">
              <label class="form-label">Provider</label>
              <select name="provider" class="form-select">
                <option value="twilio">Twilio</option>
                <option value="infobip">Infobip</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">API Key</label>
              <input type="text" name="api_key" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Sender Name</label>
              <input type="text" name="sender" class="form-control">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Save</button>
              <a href="/controllers/admin/rewards/common/integration_test.php?integration=sms" class="btn btn-outline-secondary">Send Test</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h5 class="card-title">Email Provider</h5>
          <form method="post" action="/controllers/admin/rewards/common/integration_save.php" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
            <input type="hidden" name="integration" value="email">
            <div class="col-12">
              <label class="form-label">Provider</label>
              <select name="provider" class="form-select">
                <option value="sendgrid">SendGrid</option>
                <option value="mailgun">Mailgun</option>
                <option value="smtp">SMTP</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">API Key / Password</label>
              <input type="text" name="secret" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">From Email</label>
              <input type="email" name="from_email" class="form-control">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Save</button>
              <a href="/controllers/admin/rewards/common/integration_test.php?integration=email" class="btn btn-outline-secondary">Send Test</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h5 class="card-title">Payment Gateway</h5>
          <form method="post" action="/controllers/admin/rewards/common/integration_save.php" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
            <input type="hidden" name="integration" value="payment">
            <div class="col-12">
              <label class="form-label">Provider</label>
              <select name="provider" class="form-select">
                <option value="stripe">Stripe</option>
                <option value="tap">Tap</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">Public Key</label>
              <input type="text" name="public_key" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Secret Key</label>
              <input type="text" name="secret_key" class="form-control">
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Save</button>
              <a href="/controllers/admin/rewards/common/integration_test.php?integration=payment" class="btn btn-outline-secondary">Test Connection</a>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="col-lg-6">
      <div class="card shadow-sm h-100">
        <div class="card-body">
          <h5 class="card-title">CRM / Webhooks</h5>
          <form method="post" action="/controllers/admin/rewards/common/integration_save.php" class="row g-3">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['csrf_rewards_common'] ?? '') ?>">
            <input type="hidden" name="integration" value="webhook">
            <div class="col-12">
              <label class="form-label">Endpoint URL</label>
              <input type="url" name="endpoint" class="form-control" placeholder="https://...">
            </div>
            <div class="col-12">
              <label class="form-label">Secret (optional)</label>
              <input type="text" name="secret" class="form-control">
            </div>
            <div class="col-12">
              <label class="form-label">Events</label>
              <div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" value="member.created" id="ev1"><label class="form-check-label" for="ev1">Member Created</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" value="points.earned" id="ev2"><label class="form-check-label" for="ev2">Points Earned</label></div>
              <div class="form-check"><input class="form-check-input" type="checkbox" name="events[]" value="points.redeemed" id="ev3"><label class="form-check-label" for="ev3">Points Redeemed</label></div>
            </div>
            <div class="col-12 d-flex gap-2">
              <button class="btn btn-primary">Save</button>
              <a href="/controllers/admin/rewards/common/integration_test.php?integration=webhook" class="btn btn-outline-secondary">Send Test</a>
            </div>
          </form>
        </div>
      </div>
    </div>

  </div>
</div>

<?php include dirname(__DIR__,3).'/partials/admin_footer.php'; ?>