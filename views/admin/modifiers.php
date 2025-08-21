<?php
// views/admin/modifiers.php — Modifiers list (tenant-scoped, auto-apply filters)
// UPDATED: Title "Modifiers", removed Sort column, Values column shows preview list.
declare(strict_types=1);
require_once __DIR__ . '/../../config/db.php';
use_backend_session();
$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId=(int)$user['tenant_id'];

function h($s){ return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8'); }
function column_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $q->execute([':t'=>$t,':c'=>$c]); return (bool)$q->fetchColumn();
}

$q=trim((string)($_GET['q']??'')); $stat=strtolower((string)($_GET['status']??'all')); $vis=strtolower((string)($_GET['vis']??'all'));
if(!in_array($stat,['all','active','inactive'],true)) $stat='all';
if(!in_array($vis,['all','visible','hidden'],true)) $vis='all';

$rows=[]; $valuesByGroup=[]; $db_msg='';
try {
  $pdo=db();
  if(!column_exists($pdo,'variation_groups','pos_visible')){ try{$pdo->exec("ALTER TABLE variation_groups ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");}catch(Throwable $e){} }

  $where=["vg.tenant_id=:t"]; $args=[':t'=>$tenantId];
  if($q!==''){ $where[]="vg.name LIKE :q"; $args[':q']='%'.$q.'%'; }
  if($stat==='active')   $where[]="vg.is_active=1";
  if($stat==='inactive') $where[]="vg.is_active=0";
  if($vis==='visible')   $where[]="COALESCE(vg.pos_visible,1)=1";
  if($vis==='hidden')    $where[]="COALESCE(vg.pos_visible,1)=0";
  $whereSql='WHERE '.implode(' AND ',$where);

  // Groups (sort by name; we no longer show sort_order in UI)
  $stmt=$pdo->prepare("
    SELECT vg.id, vg.name, vg.is_active, COALESCE(vg.pos_visible,1) pos_visible
    FROM variation_groups vg
    $whereSql
    ORDER BY vg.name ASC
    LIMIT 1000
  "); $stmt->execute($args); $rows=$stmt->fetchAll()?:[];

  // Fetch values for displayed groups (for preview)
  if ($rows) {
    $ids = array_map(fn($r)=> (int)$r['id'], $rows);
    $in  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare("
      SELECT group_id, value_en, price_delta
      FROM variation_values
      WHERE group_id IN ($in)
      ORDER BY group_id ASC, sort_order ASC, value_en ASC
    ");
    $st->execute($ids);
    while($v = $st->fetch(PDO::FETCH_ASSOC)){
      $g = (int)$v['group_id'];
      $label = (string)$v['value_en'];
      $d = (float)$v['price_delta'];
      if ($d) { $label .= ' (+' . number_format($d,2) . ')'; }
      $valuesByGroup[$g][] = $label;
    }
  }
} catch(Throwable $e){ $db_msg=$e->getMessage(); }

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Modifiers · Smorll POS</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
*{box-sizing:border-box} body{margin:0;background:#f7f8fa;font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1100px;margin:20px auto;padding:0 16px}
.section{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;color:#111827}
.btn:hover{filter:brightness(.98)} .btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn-add{padding:6px 12px; position:relative; top:-2px}
.actions{display:flex;gap:8px;flex-wrap:wrap}
.actions .btn{padding:6px 12px}
.actions .danger{border-color:#fecaca;background:#fee2e2;color:#7f1d1d}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.input,.select{border:1px solid var(--border);border-radius:10px;padding:8px 10px;background:#fff}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:12px;border-bottom:1px solid var(--border);text-align:left;vertical-align:top}
.table th{font-size:12px;color:var(--muted);font-weight:700}
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px;font-size:12px;background:#f3f4f6}
.badge.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.badge.off{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.badge.dim{background:#eef2ff}
.small{color:#6b7280;font-size:12px}
.flash{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0}
</style></head><body>
<?php $active='modifiers'; require __DIR__ . '/../partials/admin_nav.php'; ?>
<div class="container">
  <?php if($flash): ?><div class="flash"><?= h($flash) ?></div><?php endif; ?>
  <?php if($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>
  <div class="section">
    <div class="row" style="margin-bottom:8px">
      <div class="h1">Modifiers</div>
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
        <a class="btn btn-primary btn-add" href="/views/admin/modifier_new.php">Add</a>
      </form>
    </div>
    <div style="overflow:auto;border:1px solid var(--border);border-radius:12px">
      <table class="table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Values</th>
            <th>Status</th>
            <th>POS Visibility</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="5" class="small">No modifiers found.</td></tr>
        <?php else: foreach($rows as $r): $vals = $valuesByGroup[(int)$r['id']] ?? []; ?>
          <tr>
            <td><?= h($r['name']) ?></td>
            <td>
              <?php
                if (!$vals) {
                  echo '<span class="small">—</span>';
                } else {
                  $first = array_slice($vals, 0, 5);
                  echo h(implode(', ', $first));
                  if (count($vals) > 5) echo ' <span class="small">+'.(count($vals)-5).' more</span>';
                }
              ?>
            </td>
            <td><?= (int)$r['is_active']===1 ? '<span class="badge ok">Active</span>' : '<span class="badge off">Inactive</span>' ?></td>
            <td><?= (int)$r['pos_visible']===1 ? '<span class="badge dim">Visible</span>' : '<span class="badge off">Hidden</span>' ?></td>
            <td class="actions">
              <a class="btn" href="/views/admin/modifier_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
              <a class="btn danger" href="/controllers/admin/modifiers_delete.php?id=<?= (int)$r['id'] ?>" onclick="return confirm('Delete this modifier? This cannot be undone.')">Delete</a>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
const form=document.getElementById('filterForm'); const q=document.getElementById('q');
['status','vis'].forEach(id=>{ const el=document.getElementById(id); if(el){ el.addEventListener('change',()=>form.submit()); } });
let t=null; q.addEventListener('input',()=>{ if(t)clearTimeout(t); t=setTimeout(()=>form.submit(),350); });
</script>
</body></html>