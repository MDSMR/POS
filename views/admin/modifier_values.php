<?php
// views/admin/modifier_values.php — Values list (tenant-scoped via group), auto-apply filters
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

$group=(int)($_GET['group']??0);
$q=trim((string)($_GET['q']??'')); $stat=strtolower((string)($_GET['status']??'all')); $vis=strtolower((string)($_GET['vis']??'all'));
if(!in_array($stat,['all','active','inactive'],true)) $stat='all';
if(!in_array($vis,['all','visible','hidden'],true)) $vis='all';

$db_msg=''; $groups=[]; $rows=[]; $current=null;
try{
  $pdo=db();
  if(!column_exists($pdo,'variation_groups','pos_visible')){ try{$pdo->exec("ALTER TABLE variation_groups ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");}catch(Throwable $e){} }
  if(!column_exists($pdo,'variation_values','pos_visible')){ try{$pdo->exec("ALTER TABLE variation_values ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");}catch(Throwable $e){} }

  // groups limited to tenant
  $gs=$pdo->prepare("SELECT id,name FROM variation_groups WHERE tenant_id=:t AND is_active=1 ORDER BY sort_order ASC, name ASC");
  $gs->execute([':t'=>$tenantId]); $groups=$gs->fetchAll()?:[];
  if($group>0){
    $st=$pdo->prepare("SELECT id,name FROM variation_groups WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id'=>$group, ':t'=>$tenantId]); $current=$st->fetch();
    if(!$current){ $group=0; } // fallback to all
  }

  $where=[]; $args=[':t'=>$tenantId];
  // join group for tenant scoping
  $where[]="vg.tenant_id=:t";
  if($group>0){ $where[]="vv.group_id=:g"; $args[':g']=$group; }
  if($q!==''){ $where[]="(vv.value_en LIKE :q OR vv.value_ar LIKE :q)"; $args[':q']='%'.$q.'%'; }
  if($stat==='active')   $where[]="vv.is_active=1";
  if($stat==='inactive') $where[]="vv.is_active=0";
  if($vis==='visible')   $where[]="COALESCE(vv.pos_visible,1)=1";
  if($vis==='hidden')    $where[]="COALESCE(vv.pos_visible,1)=0";
  $whereSql='WHERE '.implode(' AND ',$where);

  $st=$pdo->prepare("
    SELECT vv.id, vv.group_id, vv.value_en, vv.value_ar, vv.price_delta, vv.sort_order, vv.is_active, COALESCE(vv.pos_visible,1) pos_visible,
           vg.name AS group_name
    FROM variation_values vv
    JOIN variation_groups vg ON vg.id=vv.group_id
    $whereSql
    ORDER BY vg.sort_order ASC, vg.name ASC, vv.sort_order ASC, vv.value_en ASC
    LIMIT 1000
  "); $st->execute($args); $rows=$st->fetchAll()?:[];
}catch(Throwable $e){ $db_msg=$e->getMessage(); }

$flash=$_SESSION['flash']??null; unset($_SESSION['flash']);
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><title>Modifier Values · Smorll POS</title><meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--border:#e5e7eb;--muted:#6b7280} *{box-sizing:border-box}
body{margin:0;background:#f7f8fa;font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1100px;margin:20px auto;padding:0 16px}
.section{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.row{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:center}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;color:#111827}
.btn:hover{filter:brightness(.98)} .btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
.btn-add{padding:6px 12px; position:relative; top:-2px}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.input,.select{border:1px solid var(--border);border-radius:10px;padding:8px 10px;background:#fff}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:12px;border-bottom:1px solid var(--border);text-align:left;vertical-align:middle}
.table th{font-size:12px;color:var(--muted);font-weight:700}
.badge{display:inline-block;border:1px solid var(--border);border-radius:999px;padding:2px 8px;font-size:12px;background:#f3f4f6}
.badge.ok{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.badge.off{background:#fee2e2;border-color:#fecaca;color:#991b1b}
.badge.dim{background:#eef2ff}
.small{color:#6b7280;font-size:12px}
</style></head><body>
<?php $active='modifiers'; require __DIR__.'/../partials/admin_nav.php'; ?>
<div class="container">
  <?php if($flash): ?><div class="small" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0"><?= h($flash) ?></div><?php endif; ?>
  <?php if($db_msg): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>

  <div class="section">
    <div class="row" style="margin-bottom:8px">
      <div class="h1">Modifier Values <?= $current?'· '.h($current['name']):'' ?></div>
      <form id="filterForm" method="get" action="" class="toolbar">
        <select class="select" name="group" id="group">
          <option value="0">All groups</option>
          <?php foreach($groups as $g): ?>
            <option value="<?= (int)$g['id'] ?>" <?= $group===(int)$g['id']?'selected':'' ?>><?= h($g['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input class="input" type="text" name="q" placeholder="Search…" value="<?= h($q) ?>" id="q">
        <select class="select" name="status" id="status">
          <option value="all" <?= $stat==='all'?'selected':'' ?>>All status</option>
          <option value="active" <?= $stat==='active'?'selected':'' ?>>Active</option>
          <option value="inactive" <?= $stat==='inactive'?'selected':'' ?>>Inactive</option>
        </select>
        <select class="select" name="vis" id="vis">
          <option value="all" <?= $vis==='all'?'selected':'' ?>>All visibility</option>
          <option value="visible" <?= $vis==='visible'?'selected':'' ?>>Visible</option>
          <option value="hidden" <?= $vis==='hidden'?'selected':'' ?>>Hidden</option>
        </select>
        <a class="btn btn-primary btn-add" href="/views/admin/modifier_value_new.php<?= $current?'?group='.(int)$current['id']:'' ?>">Add</a>
      </form>
    </div>

    <div style="overflow:auto;border:1px solid var(--border);border-radius:12px">
      <table class="table">
        <thead><tr>
          <th>Value (EN)</th><th>Value (AR)</th><th>Group</th><th>Δ Price</th><th>Sort</th><th>Status</th><th>POS Visibility</th><th>Actions</th>
        </tr></thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="8" class="small">No modifier values found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?= h($r['value_en']) ?></td>
            <td dir="rtl"><?= h($r['value_ar']) ?></td>
            <td><?= h($r['group_name'] ?? '') ?></td>
            <td><?= number_format((float)$r['price_delta'],2,'.','') ?></td>
            <td><?= h((string)$r['sort_order']) ?></td>
            <td><?= (int)$r['is_active']===1?'<span class="badge ok">Active</span>':'<span class="badge off">Inactive</span>' ?></td>
            <td><?= (int)$r['pos_visible']===1?'<span class="badge dim">Visible</span>':'<span class="badge off">Hidden</span>' ?></td>
            <td style="display:flex;gap:8px;flex-wrap:wrap">
              <a class="btn" href="/views/admin/modifier_value_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
              <a class="btn" style="border-color:#fecaca;background:#fee2e2;color:#7f1d1d" href="/controllers/admin/modifier_values_delete.php?id=<?= (int)$r['id'] ?>" onclick="return confirm('Delete this value? This cannot be undone.')">Delete</a>
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
['group','status','vis'].forEach(id=>{ const el=document.getElementById(id); if(el){ el.addEventListener('change',()=>form.submit()); } });
let t=null; q.addEventListener('input',()=>{ if(t)clearTimeout(t); t=setTimeout(()=>form.submit(),350); });
</script>
</body></html>