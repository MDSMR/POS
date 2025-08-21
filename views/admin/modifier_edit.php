<?php
// views/admin/modifier_edit.php — Edit Modifier (group + values, tenant-scoped)
// UPDATED: Order = Name → Values → Status → POS Visibility. Removed group Sort field and per-value status/vis/sort.
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
use_backend_session();

$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id'];

if (empty($_SESSION['csrf_mod'])) $_SESSION['csrf_mod'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_mod'];

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function column_exists(PDO $pdo, string $t, string $c): bool {
  $q=db()->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $q->execute([':t'=>$t, ':c'=>$c]); return (bool)$q->fetchColumn();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { $_SESSION['flash']='Modifier not specified.'; header('Location:/views/admin/modifiers.php'); exit; }

$grp=null; $values=[]; $db_msg='';
try {
  $pdo = db();
  if (!column_exists($pdo,'variation_groups','pos_visible')) { try{$pdo->exec("ALTER TABLE variation_groups ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");}catch(Throwable $e){} }

  $st=$pdo->prepare("SELECT id,name,is_active,COALESCE(pos_visible,1) pos_visible FROM variation_groups WHERE id=:id AND tenant_id=:t LIMIT 1");
  $st->execute([':id'=>$id, ':t'=>$tenantId]); $grp=$st->fetch();
  if(!$grp){ $_SESSION['flash']='Modifier not found for this tenant.'; header('Location:/views/admin/modifiers.php'); exit; }

  $st=$pdo->prepare("SELECT id, value_en, value_ar, price_delta FROM variation_values WHERE group_id=:g ORDER BY sort_order ASC, value_en ASC");
  $st->execute([':g'=>$id]); $values=$st->fetchAll()?:[];
} catch(Throwable $e){ $db_msg=$e->getMessage(); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Edit Modifier · Smorll POS</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
*{box-sizing:border-box} body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1000px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
.input,select{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:6px 12px;cursor:pointer;text-decoration:none;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98)} .btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
.actions{display:flex;gap:10px;flex-wrap:wrap}
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:12px}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}
.table th{font-size:12px;color:var(--muted);font-weight:700}
.small{color:var(--muted);font-size:12px}
</style>
</head>
<body>
<?php $active='modifiers'; require __DIR__ . '/../partials/admin_nav.php'; ?>
<div class="container">
  <?php if($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>
  <form method="post" action="/controllers/admin/modifiers_save.php" id="modForm">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$grp['id'] ?>">

    <div class="section">
      <div class="h1">Modifier Name</div>
      <input class="input" name="name" maxlength="160" required value="<?= h($grp['name']) ?>">
    </div>

    <div class="section">
      <div class="h1" style="display:flex;justify-content:space-between;align-items:center">
        <span>Modifier Values</span>
        <button class="btn btn-primary" type="button" onclick="addRow()">Add Value</button>
      </div>
      <div class="table-wrap">
        <table class="table" id="valuesTable">
          <thead>
            <tr>
              <th>Value (EN)</th>
              <th>Value (AR)</th>
              <th>Δ Price</th>
              <th style="width:80px">Remove</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($values as $v): ?>
              <tr>
                <td>
                  <input type="hidden" name="value_id[]" value="<?= (int)$v['id'] ?>">
                  <input class="input" name="value_en[]" maxlength="160" required value="<?= h($v['value_en']) ?>">
                </td>
                <td><input class="input" name="value_ar[]" maxlength="160" dir="rtl" value="<?= h($v['value_ar']) ?>"></td>
                <td><input class="input" name="price_delta[]" inputmode="decimal" value="<?= h(number_format((float)$v['price_delta'],2,'.','')) ?>"></td>
                <td><button type="button" class="btn" onclick="removeRow(this)">×</button></td>
              </tr>
            <?php endforeach; if(empty($values)): ?>
              <tr>
                <td><input class="input" name="value_en[]" maxlength="160" required></td>
                <td><input class="input" name="value_ar[]" maxlength="160" dir="rtl"></td>
                <td><input class="input" name="price_delta[]" inputmode="decimal" value="0.00"></td>
                <td><button type="button" class="btn" onclick="removeRow(this)">×</button></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div class="small" style="margin-top:8px">Price Δ is added to the product price when this value is selected.</div>
    </div>

    <div class="section">
      <div class="h1">Status</div>
      <select class="input" name="is_active">
        <option value="1" <?= (int)$grp['is_active']===1?'selected':'' ?>>Active</option>
        <option value="0" <?= (int)$grp['is_active']===0?'selected':'' ?>>Inactive</option>
      </select>
    </div>

    <div class="section">
      <div class="h1">POS Visibility</div>
      <select class="input" name="pos_visible">
        <option value="1" <?= (int)$grp['pos_visible']===1?'selected':'' ?>>Visible</option>
        <option value="0" <?= (int)$grp['pos_visible']===0?'selected':'' ?>>Hidden</option>
      </select>
    </div>

    <div class="section actions">
      <a class="btn" href="/views/admin/modifiers.php">Back</a>
      <button class="btn btn-primary" type="submit">Update</button>
    </div>
  </form>
</div>

<script>
function addRow(){
  const tbody=document.querySelector('#valuesTable tbody');
  const tr=document.createElement('tr');
  tr.innerHTML = `
    <td><input type="hidden" name="value_id[]" value=""><input class="input" name="value_en[]" maxlength="160" required></td>
    <td><input class="input" name="value_ar[]" maxlength="160" dir="rtl"></td>
    <td><input class="input" name="price_delta[]" inputmode="decimal" value="0.00"></td>
    <td><button type="button" class="btn" onclick="removeRow(this)">×</button></td>
  `;
  tbody.appendChild(tr);
}
function removeRow(btn){ btn.closest('tr').remove(); }
</script>
</body></html>