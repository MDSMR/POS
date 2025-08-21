<?php
// public_html/views/admin/categories_new.php — New Category (flat; with POS visibility)
// UPDATED: Removed Sort field from the form. Minor UX/guard tweaks.
declare(strict_types=1);

/* Bootstrap + session */
$bootstrap_warning = ''; $bootstrap_ok=false;
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) {
  $bootstrap_warning='Configuration file not found: /config/db.php';
} else {
  $prev=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path;
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok=true; }
  } catch (Throwable $e){ $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally { if ($prev){ set_error_handler($prev); } }
}
if ($bootstrap_ok){ try { use_backend_session(); } catch(Throwable $e){ $bootstrap_warning=$bootstrap_warning?:('Session bootstrap error: '.$e->getMessage()); } }

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* CSRF */
if (empty($_SESSION['csrf_categories'])) { $_SESSION['csrf_categories'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_categories'];

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>New Category · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:800px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
@media (max-width:900px){.grid{grid-template-columns:1fr}}
label{font-size:12px;color:#6b7280;display:block;margin-bottom:6px}
.input, select{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px}

/* Buttons */
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:6px 12px;cursor:pointer;text-decoration:none;display:inline-block;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98)}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.actions{display:flex;gap:10px}
.small{color:#6b7280;font-size:12px}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
</style>
</head>
<body>

<?php $active='categories'; require __DIR__ . '/../partials/admin_nav.php'; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>

  <form method="post" action="/controllers/admin/categories_save.php" id="categoryForm" novalidate>
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">

    <div class="section">
      <div class="h1">New Category</div>

      <div class="grid">
        <div>
          <label>Name (English)</label>
          <input class="input" name="name_en" maxlength="200" required placeholder="e.g., Salads" autocomplete="off">
        </div>
        <div>
          <label>Arabic Name (الاسم العربي)</label>
          <input class="input" name="name_ar" maxlength="200" dir="rtl" placeholder="سلطات" autocomplete="off">
        </div>
      </div>

      <div class="grid">
        <div>
          <label>POS Visibility</label>
          <select class="input" name="pos_visible">
            <option value="1" selected>Visible</option>
            <option value="0">Hidden</option>
          </select>
          <div class="small">Hidden categories won’t appear in the POS.</div>
        </div>
        <div>
          <label>Status</label>
          <select class="input" name="is_active">
            <option value="1" selected>Active</option>
            <option value="0">Inactive</option>
          </select>
        </div>
      </div>

      <div class="actions" style="margin-top:12px">
        <a class="btn" href="/views/admin/categories.php">Back</a>
        <button class="btn btn-primary" type="submit" id="submitBtn">Create</button>
      </div>
    </div>
  </form>
</div>

<script>
// Prevent double submit & do a tiny required check
const form = document.getElementById('categoryForm');
const btn  = document.getElementById('submitBtn');
form.addEventListener('submit', function(e){
  const nameEn = form.querySelector('[name="name_en"]');
  if (!nameEn.value.trim()) {
    e.preventDefault();
    alert('Please enter the English name.');
    nameEn.focus();
    return;
  }
  btn.disabled = true;
  btn.textContent = 'Creating…';
});
</script>
</body>
</html>