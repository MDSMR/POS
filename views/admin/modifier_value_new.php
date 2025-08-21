<?php
// views/admin/modifier_value_new.php — New value (tenant-scoped groups)
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
use_backend_session();

$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id'];

if(empty($_SESSION['csrf_modval'])) $_SESSION['csrf_modval']=bin2hex(random_bytes(32)); $csrf=$_SESSION['csrf_modval'];
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}

$group=(int)($_GET['group']??0);
$pdo=db();
$groups=$pdo->prepare("SELECT id,name FROM variation_groups WHERE tenant_id=:t AND is_active=1 ORDER BY sort_order ASC, name ASC");
$groups->execute([':t'=>$tenantId]); $groups=$groups->fetchAll()?:[];
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>New Modifier Value · Smorll POS</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--border:#e5e7eb} *{box-sizing:border-box}
body{margin:0;background:#f7f8fa;font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:800px;margin:20px auto;padding:0 16px}
.section{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
label{font-size:12px;color:#6b7280;display:block;margin-bottom:6px}
.input,select{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px} @media(max-width:900px){.grid{grid-template-columns:1fr}}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:6px 12px;cursor:pointer;text-decoration:none;color:#111827}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.actions{display:flex;gap:10px}
</style></head><body>
<?php $active='modifiers'; require __DIR__.'/../partials/admin_nav.php'; ?>
<div class="container">
  <form method="post" action="/controllers/admin/modifier_values_save.php">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <div class="section">
      <div class="h1">New Modifier Value</div>
      <div class="grid">
        <div><label>Group</label>
          <select class="input" name="group_id" required>
            <option value="">Select a group…</option>
            <?php foreach($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= $group===(int)$g['id']?'selected':'' ?>><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Sort Order</label><input class="input" name="sort_order" type="number" value="1"></div>
      </div>
      <div class="grid">
        <div><label>Value (English)</label><input class="input" name="value_en" maxlength="160" required></div>
        <div><label>Value (Arabic)</label><input class="input" name="value_ar" maxlength="160" dir="rtl"></div>
      </div>
      <div class="grid">
        <div><label>Price Delta</label><input class="input" name="price_delta" inputmode="decimal" placeholder="0.00" value="0.00"></div>
        <div></div>
      </div>
      <div class="grid">
        <div><label>Status</label><select class="input" name="is_active"><option value="1" selected>Active</option><option value="0">Inactive</option></select></div>
        <div><label>POS Visibility</label><select class="input" name="pos_visible"><option value="1" selected>Visible</option><option value="0">Hidden</option></select></div>
      </div>
      <div class="actions" style="margin-top:12px">
        <a class="btn" href="/views/admin/modifier_values.php<?= $group>0?'?group='.$group:'' ?>">Back</a>
        <button class="btn btn-primary" type="submit">Create</button>
      </div>
    </div>
  </form>
</div>
</body></html>