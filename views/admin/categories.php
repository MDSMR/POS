<?php
// views/admin/categories.php — Categories list with Search + Status + Visibility filters (auto-apply)
// UPDATED: Removed "Sort" column from the table view. Added tenant scoping for security.
declare(strict_types=1);

/* Bootstrap + session */
$bootstrap_warning = ''; $bootstrap_ok=false;
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) {
  $bootstrap_warning='Configuration file not found: /config/db.php';
} else {
  $prevHandler=set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path;
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning='Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok=true; }
  } catch (Throwable $e) { $bootstrap_warning='Bootstrap error: '.$e->getMessage(); }
  finally { if ($prevHandler){ set_error_handler($prevHandler); } }
}
if ($bootstrap_ok) {
  try { use_backend_session(); } catch (Throwable $e){
    $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: '.$e->getMessage());
  }
}

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function column_exists(PDO $pdo, string $table, string $col): bool {
  try {
    $q=$pdo->prepare("SELECT 1
                      FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE()
                        AND TABLE_NAME = :t
                        AND COLUMN_NAME = :c");
    $q->execute([':t'=>$table, ':c'=>$col]);
    return (bool)$q->fetchColumn();
  } catch (Throwable $e){ return false; }
}

/* Ensure pos_visible exists for categories */
if ($bootstrap_ok) {
  try {
    $pdo = db();
    if (!column_exists($pdo, 'categories', 'pos_visible')) {
      try {
        $pdo->exec("ALTER TABLE categories
                    ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1
                    AFTER is_active");
      } catch(Throwable $e){}
    }
  } catch (Throwable $e) { /* ignore here */ }
}

/* Filters */
$q    = trim((string)($_GET['q'] ?? ''));
$stat = strtolower((string)($_GET['status'] ?? 'all'));
$vis  = strtolower((string)($_GET['vis'] ?? 'all'));
if (!in_array($stat, ['all','active','inactive'], true)) $stat='all';
if (!in_array($vis,  ['all','visible','hidden'], true))  $vis ='all';

/* Load data */
$rows = []; $db_msg='';
if ($bootstrap_ok) {
  try {
    $pdo = db();
    $where = ["c.tenant_id = :t"];
    $args  = [':t' => $tenantId];

    if ($q !== '') { $where[] = "(c.name_en LIKE :q OR c.name_ar LIKE :q)"; $args[':q'] = '%'.$q.'%'; }
    if ($stat === 'active')   $where[] = "c.is_active = 1";
    if ($stat === 'inactive') $where[] = "c.is_active = 0";
    if ($vis  === 'visible')  $where[] = "COALESCE(c.pos_visible,1) = 1";
    if ($vis  === 'hidden')   $where[] = "COALESCE(c.pos_visible,1) = 0";

    $whereSql = $where ? ('WHERE '.implode(' AND ',$where)) : '';

    $sql = "
      SELECT c.id, c.name_en, c.name_ar, c.sort_order, c.is_active,
             COALESCE(c.pos_visible,1) AS pos_visible
      FROM categories c
      $whereSql
      ORDER BY c.sort_order ASC, c.name_en ASC
      LIMIT 1000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $rows = $stmt->fetchAll() ?: [];
  } catch (Throwable $e) {
    $db_msg = $e->getMessage();
  }
}

/* Flash */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Categories · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb;--danger:#dc2626;--ok:#059669}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1100px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}

/* Buttons */
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;display:inline-block;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98)}
.btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn-add{padding:6px 12px; position:relative; top:-2px;}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.actions .btn{padding:6px 12px; line-height:1.1;}
.actions .danger{border-color:#fecaca;background:#fee2e2;color:#7f1d1d}

/* Toolbar */
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.input{border:1px solid var(--border);border-radius:10px;padding:8px 10px;background:#fff}
.select{border:1px solid var(--border);border-radius:10px;padding:8px 10px;background:#fff}
.small{color:#6b7280; font-size:12px}

/* Table */
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:12px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}
.table th{font-size:12px;color:#6b7280;font-weight:700}
.table tr:hover td{background:#fafafa}
.nameen{font-weight:600;color:#111827}
.namear{color:#6b7280;font-size:12px;direction:rtl}
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px;font-size:12px;background:#f3f4f6}
.badge.ok{border-color:#a7f3d0;background:#ecfdf5;color:#065f46}
.badge.off{color:#991b1b;background:#fee2e2;border-color:#fecaca}
.badge.dim{background:#eef2ff}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
</style>
</head>
<body>

<?php $active='categories'; require __DIR__ . '/../partials/admin_nav.php'; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>

  <div class="section">
    <div class="row" style="margin-bottom:8px">
      <div class="h1">Categories</div>

      <form id="filterForm" method="get" action="" class="toolbar">
        <input class="input" type="text" name="q" placeholder="Search…" value="<?= h($q) ?>" id="q">
        <select class="select" name="status" id="status">
          <option value="all"      <?= $stat==='all'?'selected':'' ?>>All status</option>
          <option value="active"   <?= $stat==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $stat==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        <select class="select" name="vis" id="vis">
          <option value="all"     <?= $vis==='all'?'selected':'' ?>>All visibility</option>
          <option value="visible" <?= $vis==='visible'?'selected':'' ?>>Visible</option>
          <option value="hidden"  <?= $vis==='hidden'?'selected':'' ?>>Hidden</option>
        </select>

        <a class="btn btn-primary btn-add" href="/views/admin/categories_new.php">Add</a>
      </form>
    </div>

    <div style="overflow:auto;border:1px solid var(--border);border-radius:12px">
      <table class="table">
        <thead>
          <tr>
            <th>Category</th>
            <!-- Sort column removed -->
            <th>Status</th>
            <th>POS Visibility</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="4" class="small">No categories found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td>
              <div class="nameen"><?= h($r['name_en']) ?></div>
              <?php if (!empty($r['name_ar'])): ?>
                <div class="namear"><?= h($r['name_ar']) ?></div>
              <?php endif; ?>
            </td>
            <!-- Sort cell removed -->
            <td><?= (int)$r['is_active']===1 ? '<span class="badge ok">Active</span>' : '<span class="badge off">Inactive</span>' ?></td>
            <td><?= (int)$r['pos_visible']===1 ? '<span class="badge dim">Visible</span>' : '<span class="badge off">Hidden</span>' ?></td>
            <td class="actions">
              <a class="btn" href="/views/admin/category_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
              <a class="btn danger" href="/controllers/admin/categories_delete.php?id=<?= (int)$r['id'] ?>"
                 onclick="return confirm('Delete this category? This cannot be undone.')">Delete</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
// Auto-submit filters (debounce text input)
const form = document.getElementById('filterForm');
const q = document.getElementById('q');
['status','vis'].forEach(id=>{
  const el=document.getElementById(id);
  if(el){ el.addEventListener('change', ()=>form.submit()); }
});
let t=null;
q.addEventListener('input', ()=>{
  if(t) clearTimeout(t);
  t=setTimeout(()=>form.submit(), 350);
});
</script>
</body>
</html>