<?php
// views/admin/products.php — Products list with Search + Status + Visibility + Category + Branch filters (auto-apply)
declare(strict_types=1);

/* Bootstrap + session */
$bootstrap_warning = ''; $bootstrap_ok=false;
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) { $bootstrap_warning='Configuration file not found: /config/db.php';
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
if ($bootstrap_ok) { try { use_backend_session(); } catch (Throwable $e){ $bootstrap_warning=$bootstrap_warning?:('Session bootstrap error: '.$e->getMessage()); } }

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function table_exists(PDO $pdo, string $t): bool {
  try {$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t"); $q->execute([':t'=>$t]); return (bool)$q->fetchColumn();} catch(Throwable $e){return false;}
}
function column_exists(PDO $pdo, string $t, string $c): bool {
  try {$q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c"); $q->execute([':t'=>$t, ':c'=>$c]); return (bool)$q->fetchColumn();} catch(Throwable $e){return false;}
}

/* Filters */
$q    = trim((string)($_GET['q'] ?? ''));                 // search by EN/AR name
$stat = strtolower((string)($_GET['status'] ?? 'all'));   // all|active|inactive
$vis  = strtolower((string)($_GET['vis'] ?? 'all'));      // all|visible|hidden
$cat  = (int)($_GET['cat'] ?? 0);                         // category id
$br   = (int)($_GET['br']  ?? 0);                         // branch id
if (!in_array($stat, ['all','active','inactive'], true)) $stat='all';
if (!in_array($vis,  ['all','visible','hidden'], true))  $vis ='all';

/* Query */
$rows = []; $db_msg = '';
$categories = $branches = [];
if ($bootstrap_ok) {
  try {
    $pdo = db();

    // Filter sources
    try {
      $categories = $pdo->query("SELECT id, name_en FROM categories WHERE is_active=1 ORDER BY sort_order ASC, name_en ASC")->fetchAll() ?: [];
    } catch(Throwable $e){ $categories=[]; }
    try {
      $blabel = column_exists($pdo, 'branches', 'name') ? 'name' : (column_exists($pdo,'branches','title')?'title':null);
      if ($blabel) {
        $branches = $pdo->query("SELECT id, `$blabel` AS name FROM branches ORDER BY `$blabel` ASC")->fetchAll() ?: [];
      } else { $branches = []; }
    } catch(Throwable $e){ $branches=[]; }

    // Feature detection
    $has_pc   = table_exists($pdo, 'product_categories');
    $has_pb   = table_exists($pdo, 'product_branches');
    $has_cats = table_exists($pdo, 'categories');
    $has_b    = table_exists($pdo, 'branches');

    $branchLabel = 'name';
    if ($has_b) {
      if (column_exists($pdo, 'branches', 'name'))       $branchLabel = 'name';
      elseif (column_exists($pdo, 'branches', 'title'))  $branchLabel = 'title';
      else $has_b = false;
    }

    $select = [
      "p.id","p.name_en","p.name_ar","p.price","p.standard_cost","p.is_active","p.pos_visible","p.updated_at"
    ];
    $joins = [];
    $where = []; $args = [];

    // Smart filters
    if ($q !== '') { $where[]="(p.name_en LIKE :q OR p.name_ar LIKE :q)"; $args[':q'] = '%'.$q.'%'; }
    if ($stat === 'active')   $where[]="p.is_active=1";
    if ($stat === 'inactive') $where[]="p.is_active=0";
    if ($vis  === 'visible')  $where[]="p.pos_visible=1";
    if ($vis  === 'hidden')   $where[]="p.pos_visible=0";

    // Category constraint
    if ($cat > 0 && $has_pc) {
      $where[] = "EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id=p.id AND pc.category_id=:cat)";
      $args[':cat'] = $cat;
    }

    // Branch constraint
    if ($br > 0 && $has_pb) {
      $where[] = "EXISTS (SELECT 1 FROM product_branches pb WHERE pb.product_id=p.id AND pb.branch_id=:br)";
      $args[':br'] = $br;
    }

    // Categories tags
    if ($has_pc && $has_cats) {
      $select[] = "COALESCE(cat.categories,'') AS categories";
      $joins[] = "
        LEFT JOIN (
          SELECT pc.product_id, GROUP_CONCAT(DISTINCT c.name_en ORDER BY c.name_en SEPARATOR ', ') AS categories
          FROM product_categories pc
          JOIN categories c ON c.id=pc.category_id
          GROUP BY pc.product_id
        ) cat ON cat.product_id=p.id
      ";
    } else { $select[]="'' AS categories"; }

    // Branches tags
    if ($has_pb && $has_b) {
      $select[] = "COALESCE(br.branches,'') AS branches";
      $joins[] = "
        LEFT JOIN (
          SELECT pb.product_id, GROUP_CONCAT(DISTINCT b.`$branchLabel` ORDER BY b.`$branchLabel` SEPARATOR ', ') AS branches
          FROM product_branches pb
          JOIN branches b ON b.id=pb.branch_id
          GROUP BY pb.product_id
        ) br ON br.product_id=p.id
      ";
    } else { $select[]="'' AS branches"; }

    $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

    $sql = "
      SELECT ".implode(", ", $select)."
      FROM products p
      ".implode("\n      ", $joins)."
      $whereSql
      ORDER BY (p.updated_at IS NULL), p.updated_at DESC, p.id DESC
      LIMIT 500
    ";
    $stmt = $pdo->prepare($sql); $stmt->execute($args);
    $rows = $stmt->fetchAll() ?: [];
  } catch (Throwable $e) { $db_msg = $e->getMessage(); }
}

/* Flash */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Products · Smorll POS</title>
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
.small{color:#6b7280;font-size:12px}

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
.tag{display:inline-block;background:#eef2ff;border:1px solid #e5e7eb;border-radius:999px;padding:2px 8px;margin:2px 4px 0 0;font-size:12px}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
@media (max-width:950px){ .hide-md{display:none} }
</style>
</head>
<body>

<?php $active='products'; require __DIR__ . '/../partials/admin_nav.php'; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>
  <?php if ($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>

  <div class="section">
    <div class="row" style="margin-bottom:8px">
      <div class="h1">Products</div>
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
        <select class="select" name="cat" id="cat">
          <option value="0">All categories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>><?= h($c['name_en']) ?></option>
          <?php endforeach; ?>
        </select>
        <select class="select" name="br" id="br">
          <option value="0">All branches</option>
          <?php foreach ($branches as $b): ?>
            <option value="<?= (int)$b['id'] ?>" <?= $br===(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <a class="btn btn-primary btn-add" href="/views/admin/products_new.php">Add</a>
      </form>
    </div>

    <div style="overflow:auto;border:1px solid var(--border);border-radius:12px">
      <table class="table">
        <thead>
          <tr>
            <th>Product</th>
            <th class="hide-md">Price</th>
            <th>Cost</th>
            <th>Categories</th>
            <th>Branches</th>
            <th class="hide-md">Status</th>
            <th>POS Visibility</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="8" class="small">No products found.</td></tr>
        <?php else: foreach ($rows as $r): ?>
          <tr>
            <td>
              <div class="nameen"><?= h($r['name_en']) ?></div>
              <?php if (!empty($r['name_ar'])): ?><div class="namear"><?= h($r['name_ar']) ?></div><?php endif; ?>
            </td>
            <td class="hide-md"><?= h(number_format((float)$r['price'], 2, '.', '')) ?></td>
            <td><?= h(number_format((float)$r['standard_cost'], 2, '.', '')) ?></td>
            <td>
              <?php
                $tags=[];
                if (!empty($r['categories'])) {
                  foreach (explode(',', $r['categories']) as $cn){ $cn=trim($cn); if($cn!=='') $tags[]='<span class="tag">'.h($cn).'</span>'; }
                }
                echo $tags ? implode(' ', $tags) : '<span class="small">—</span>';
              ?>
            </td>
            <td>
              <?php
                $tags=[];
                if (!empty($r['branches'])) {
                  foreach (explode(',', $r['branches']) as $bn){ $bn=trim($bn); if($bn!=='') $tags[]='<span class="tag">'.h($bn).'</span>'; }
                }
                echo $tags ? implode(' ', $tags) : '<span class="small">—</span>';
              ?>
            </td>
            <td class="hide-md"><?= (int)$r['is_active']===1 ? '<span class="badge ok">Active</span>' : '<span class="badge off">Inactive</span>' ?></td>
            <td><?= (int)$r['pos_visible']===1 ? '<span class="badge dim">Visible</span>' : '<span class="badge off">Hidden</span>' ?></td>
            <td class="actions">
              <a class="btn" href="/views/admin/product_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
              <a class="btn danger" href="/controllers/admin/products_delete.php?id=<?= (int)$r['id'] ?>"
                onclick="return confirm('Delete this product? This cannot be undone.')">Delete</a>
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
['status','vis','cat','br'].forEach(id=>{
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