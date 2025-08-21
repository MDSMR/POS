<?php
require_once __DIR__ . '/../_header.php';

$tenantId = tenant_id();
$q = trim($_GET['q'] ?? '');
$params = [':t'=>$tenantId];
$sql = "SELECT c.id, c.name_en, c.name_ar, c.sort_order, c.is_active,
               p.name_en AS parent_name
        FROM categories c
        LEFT JOIN categories p ON p.id = c.parent_id
        WHERE c.tenant_id = :t";
if ($q !== '') { $sql .= " AND (c.name_en LIKE :q OR c.name_ar LIKE :q)"; $params[':q']="%$q%"; }
$sql .= " ORDER BY c.sort_order, c.name_en";
$rows = db()->prepare($sql); $rows->execute($params); $rows = $rows->fetchAll();
?>
<div class="card">
  <div class="card-head">
    <h2>Categories</h2>
    <form method="get" class="filters" style="display:flex;gap:8px">
      <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search…">
      <button class="btn">Filter</button>
      <a class="btn" href="index.php">Reset</a>
      <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/categories/index.php?act=new')) ?>">+ New</a>
    </form>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>#</th><th>Name (EN)</th><th>Name (AR)</th><th>Parent</th><th>Sort</th><th>Status</th><th style="width:160px"></th></tr></thead>
      <tbody>
        <?php if ($rows): $i=1; foreach ($rows as $r): ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($r['name_en']) ?></td>
            <td><?= htmlspecialchars($r['name_ar']) ?></td>
            <td><?= htmlspecialchars($r['parent_name'] ?? '—') ?></td>
            <td><?= (int)$r['sort_order'] ?></td>
            <td><?= $r['is_active'] ? 'Active' : 'Hidden' ?></td>
            <td>
              <a class="btn" href="index.php?act=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              <a class="btn" href="<?= htmlspecialchars(base_url('controllers/categories/delete.php?id='.(int)$r['id'])) ?>" onclick="return confirm('Delete this category?')">Delete</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7" class="muted">No categories yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
// Form (new/edit)
if (($_GET['act'] ?? '') === 'new' || (($_GET['act'] ?? '') === 'edit' && !empty($_GET['id']))) {
  $editing = ($_GET['act'] ?? '') === 'edit';
  $cat = ['id'=>0,'name_en'=>'','name_ar'=>'','parent_id'=>null,'sort_order'=>0,'is_active'=>1];
  if ($editing) {
    $st = db()->prepare("SELECT * FROM categories WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id'=>(int)$_GET['id'], ':t'=>$tenantId]);
    $cat = $st->fetch() ?: $cat;
  }
  $parents = db()->prepare("SELECT id, name_en FROM categories WHERE tenant_id=:t AND id<>:id ORDER BY name_en");
  $parents->execute([':t'=>$tenantId, ':id'=>(int)$cat['id']]);
  $parents = $parents->fetchAll();
?>
<div class="card" style="margin-top:12px">
  <div class="card-head"><h2><?= $editing ? 'Edit' : 'New' ?> Category</h2></div>
  <form method="post" action="<?= htmlspecialchars(base_url('controllers/categories/save.php')) ?>" style="padding:12px;display:grid;gap:10px;max-width:700px">
    <input type="hidden" name="id" value="<?= (int)$cat['id'] ?>">
    <label>Name (EN)
      <input name="name_en" required maxlength="150" value="<?= htmlspecialchars($cat['name_en']) ?>">
    </label>
    <label>Name (AR)
      <input name="name_ar" maxlength="150" value="<?= htmlspecialchars($cat['name_ar']) ?>">
    </label>
    <label>Parent category
      <select name="parent_id">
        <option value="">— None —</option>
        <?php foreach ($parents as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$cat['parent_id']===(int)$p['id']?'selected':'' ?>>
            <?= htmlspecialchars($p['name_en']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Sort order
      <input type="number" name="sort_order" value="<?= (int)$cat['sort_order'] ?>">
    </label>
    <label>Status
      <select name="is_active">
        <option value="1" <?= $cat['is_active']?'selected':'' ?>>Active</option>
        <option value="0" <?= !$cat['is_active']?'selected':'' ?>>Hidden</option>
      </select>
    </label>
    <div style="display:flex;gap:8px">
      <button class="btn" type="submit">Save</button>
      <a class="btn" href="index.php">Cancel</a>
    </div>
  </form>
</div>
<?php } ?>

<?php require __DIR__ . '/../_footer.php'; ?>