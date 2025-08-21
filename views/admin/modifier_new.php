<?php
// views/admin/modifier_new.php — New Modifier (group-level)
// UPDATED: Field order = Name → Values → Status → POS Visibility. Removed group Sort. No per-value status/vis/sort.
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
use_backend_session();
$user = $_SESSION['user'] ?? null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
if (empty($_SESSION['csrf_mod'])) $_SESSION['csrf_mod']=bin2hex(random_bytes(32)); $csrf=$_SESSION['csrf_mod'];
function h($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>New Modifier · Smorll POS</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--border:#e5e7eb;--muted:#6b7280}
*{box-sizing:border-box}
body{margin:0;background:#f7f8fa;font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:900px;margin:20px auto;padding:0 16px}
.section{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
.input,select{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:6px 12px;cursor:pointer;text-decoration:none;color:#111827;line-height:1.1}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.actions{display:flex;gap:10px}
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:12px}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:10px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}
.table th{font-size:12px;color:var(--muted);font-weight:700}
.small{color:var(--muted);font-size:12px}
</style></head><body>
<?php $active='modifiers'; require __DIR__.'/../partials/admin_nav.php'; ?>
<div class="container">
  <form method="post" action="/controllers/admin/modifiers_save.php" id="modForm">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

    <div class="section">
      <div class="h1">Modifier Name</div>
      <input class="input" name="name" maxlength="160" required placeholder="e.g., Size">
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
            <tr>
              <td><input class="input" name="value_en[]" maxlength="160" required></td>
              <td><input class="input" name="value_ar[]" maxlength="160" dir="rtl"></td>
              <td><input class="input" name="price_delta[]" inputmode="decimal" value="0.00"></td>
              <td><button type="button" class="btn" onclick="removeRow(this)">×</button></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div class="small" style="margin-top:8px">Price Δ is added to the product price when this value is selected.</div>
    </div>

    <div class="section">
      <div class="h1">Status</div>
      <select class="input" name="is_active">
        <option value="1" selected>Active</option>
        <option value="0">Inactive</option>
      </select>
    </div>

    <div class="section">
      <div class="h1">POS Visibility</div>
      <select class="input" name="pos_visible">
        <option value="1" selected>Visible</option>
        <option value="0">Hidden</option>
      </select>
    </div>

    <div class="section actions">
      <a class="btn" href="/views/admin/modifiers.php">Back</a>
      <button class="btn btn-primary" type="submit">Create</button>
    </div>
  </form>
</div>

<script>
function addRow(){
  const tbody=document.querySelector('#valuesTable tbody');
  const tr=document.createElement('tr');
  tr.innerHTML = `
    <td><input class="input" name="value_en[]" maxlength="160" required></td>
    <td><input class="input" name="value_ar[]" maxlength="160" dir="rtl"></td>
    <td><input class="input" name="price_delta[]" inputmode="decimal" value="0.00"></td>
    <td><button type="button" class="btn" onclick="removeRow(this)">×</button></td>
  `;
  tbody.appendChild(tr);
}
function removeRow(btn){ btn.closest('tr').remove(); }
</script>
</body></html>