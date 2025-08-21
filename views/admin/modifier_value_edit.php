<?php
// views/admin/modifier_value_edit.php — Edit Modifier Value
declare(strict_types=1);
$bootstrap_path=__DIR__.'/../../config/db.php'; require_once $bootstrap_path; use_backend_session();
$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
if(empty($_SESSION['csrf_modval'])) $_SESSION['csrf_modval']=bin2hex(random_bytes(32)); $csrf=$_SESSION['csrf_modval'];
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
$id=(int)($_GET['id']??0); if($id<=0){ $_SESSION['flash']='Value not specified.'; header('Location:/views/admin/modifier_values.php'); exit; }
$pdo=db(); $groups=$pdo->query("SELECT id,name FROM variation_groups WHERE is_active=1 ORDER BY sort_order ASC, name ASC")->fetchAll()?:[];
$val=null; try{
  // ensure pos_visible
  $pdo->exec("CREATE TEMPORARY TABLE IF NOT EXISTS _x (i int)");
  $ch=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='variation_values' AND COLUMN_NAME='pos_visible'"); $ch->execute();
  if(!$ch->fetchColumn()){ try{$pdo->exec("ALTER TABLE variation_values ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");}catch(Throwable $e){} }
  $st=$pdo->prepare("SELECT id, group_id, value_en, value_ar, price_delta, sort_order, is_active, COALESCE(pos_visible,1) pos_visible FROM variation_values WHERE id=:id LIMIT 1");
  $st->execute([':id'=>$id]); $val=$st->fetch();
  if(!$val){ $_SESSION['flash']='Value not found.'; header('Location:/views/admin/modifier_values.php'); exit; }
}catch(Throwable $e){ $_SESSION['flash']='Load error. '.$e->getMessage(); header('Location:/views/admin/modifier_values.php'); exit; }
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Edit Modifier Value · Smorll POS</title><meta name="viewport" content="width=device-width, initial-scale=1">
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
    <input type="hidden" name="id" value="<?= (int)$val['id'] ?>">
    <div class="section">
      <div class="h1">Edit Modifier Value</div>
      <div class="grid">
        <div><label>Group</label>
          <select class="input" name="group_id" required>
            <?php foreach($groups as $g): ?>
              <option value="<?= (int)$g['id'] ?>" <?= (int)$val['group_id'] === (int)$g['id']?'selected':'' ?>><?= h($g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label>Sort Order</label><input class="input" name="sort_order" type="number" value="<?= h((string)$val['sort_order']) ?>"></div>
      </div>
      <div class="grid">
        <div><label>Value (English)</label><input class="input" name="value_en" maxlength="160" required value="<?= h($val['value_en']) ?>"></div>
        <div><label>Value (Arabic)</label><input class="input" name="value_ar" maxlength="160" dir="rtl" value="<?= h($val['value_ar']) ?>"></div>
      </div>
      <div class="grid">
        <div><label>Price Delta</label><input class="input" name="price_delta" inputmode="decimal" value="<?= h(number_format((float)$val['price_delta'],2,'.','')) ?>"></div>
        <div></div>
      </div>
      <div class="grid">
        <div><label>Status</label><select class="input" name="is_active"><option value="1" <?= (int)$val['is_active']===1?'selected':'' ?>>Active</option><option value="0" <?= (int)$val['is_active']===0?'selected':'' ?>>Inactive</option></select></div>
        <div><label>POS Visibility</label><select class="input" name="pos_visible"><option value="1" <?= (int)$val['pos_visible']===1?'selected':'' ?>>Visible</option><option value="0" <?= (int)$val['pos_visible']===0?'selected':'' ?>>Hidden</option></select></div>
      </div>
      <div class="actions" style="margin-top:12px">
        <a class="btn" href="/views/admin/modifier_values.php?group=<?= (int)$val['group_id'] ?>">Back</a>
        <button class="btn btn-primary" type="submit">Update</button>
      </div>
    </div>
  </form>
</div>
</body></html>