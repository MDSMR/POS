<?php
require_once __DIR__ . '/../_header.php';

$tenantId = tenant_id();
$q = trim($_GET['q'] ?? '');
$cat = (int)($_GET['category_id'] ?? 0);

$params = [':t'=>$tenantId];
$sql = "SELECT p.id, p.name_en, p.name_ar, p.price, p.pos_visible, p.is_active,
               GROUP_CONCAT(c.name_en ORDER BY c.name_en SEPARATOR ', ') AS cats
        FROM products p
        LEFT JOIN product_categories pc ON pc.product_id = p.id
        LEFT JOIN categories c ON c.id = pc.category_id
        WHERE p.tenant_id = :t";
if ($q !== '') { $sql .= " AND (p.name_en LIKE :q OR p.name_ar LIKE :q)"; $params[':q']="%$q%"; }
if ($cat) { $sql .= " AND EXISTS (SELECT 1 FROM product_categories pc2 WHERE pc2.product_id=p.id AND pc2.category_id=:cat)"; $params[':cat']=$cat; }
$sql .= " GROUP BY p.id ORDER BY p.name_en";
$rows = db()->prepare($sql); $rows->execute($params); $rows = $rows->fetchAll();

$cats = db()->prepare("SELECT id, name_en FROM categories WHERE tenant_id=:t ORDER BY name_en");
$cats->execute([':t'=>$tenantId]); $cats = $cats->fetchAll();
?>
<div class="card">
  <div class="card-head">
    <h2>Items</h2>
    <form method="get" class="filters" style="display:flex;gap:8px;align-items:center">
      <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search…">
      <select name="category_id">
        <option value="0">All categories</option>
        <?php foreach($cats as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $cat===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['name_en']) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn">Filter</button>
      <a class="btn" href="index.php">Reset</a>
      <a class="btn" href="index.php?act=new">+ New</a>
    </form>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th>#</th>
        <th>Name</th>
        <th>Categories</th>
        <th style="text-align:right">Price</th>
        <th>POS</th>
        <th>Status</th>
        <th style="width:360px"></th>
      </tr></thead>
      <tbody>
        <?php if ($rows): $i=1; foreach ($rows as $r): $pid=(int)$r['id']; ?>
          <tr>
            <td><?= $i++ ?></td>
            <td><?= htmlspecialchars($r['name_en']) ?></td>
            <td><?= htmlspecialchars($r['cats'] ?? '—') ?></td>
            <td style="text-align:right">KD <?= number_format((float)$r['price'],3) ?></td>
            <td><?= $r['pos_visible'] ? 'Visible' : 'Hidden' ?></td>
            <td><?= $r['is_active'] ? 'Active' : 'Off' ?></td>
            <td>
              <a class="btn" href="index.php?act=edit&id=<?= $pid ?>">Edit</a>
              <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/items/variations.php?product_id='.$pid)) ?>">Variations</a>
              <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/items/branch_availability.php?product_id='.$pid)) ?>">Branches</a>
              <a class="btn" href="<?= htmlspecialchars(base_url('controllers/items/delete.php?id='.$pid)) ?>" onclick="return confirm('Delete this item?')">Delete</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="7" class="muted">No items yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php
// Form (new/edit)
if (($_GET['act'] ?? '') === 'new' || (($_GET['act'] ?? '') === 'edit' && !empty($_GET['id']))) {
  $editing = ($_GET['act'] ?? '') === 'edit';
  $item = ['id'=>0,'name_en'=>'','name_ar'=>'','price'=>'0.000','is_open_price'=>0,'pos_visible'=>1,'is_active'=>1];
  if ($editing) {
    $st = db()->prepare("SELECT * FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id'=>(int)$_GET['id'], ':t'=>$tenantId]);
    $item = $st->fetch() ?: $item;
  }
  $catsAll = db()->prepare("SELECT id,name_en FROM categories WHERE tenant_id=:t ORDER BY name_en");
  $catsAll->execute([':t'=>$tenantId]); $catsAll = $catsAll->fetchAll();
  $sel = [];
  if ($editing) {
    $pc = db()->prepare("SELECT category_id FROM product_categories WHERE product_id=:pid");
    $pc->execute([':pid'=>(int)$item['id']]);
    $sel = array_map(fn($r)=> (int)$r['category_id'], $pc->fetchAll(PDO::FETCH_ASSOC));
  }
?>
<div class="card" style="margin-top:12px">
  <div class="card-head"><h2><?= $editing ? 'Edit' : 'New' ?> Item</h2></div>
  <form method="post" action="<?= htmlspecialchars(base_url('controllers/items/save.php')) ?>" style="padding:12px;display:grid;gap:10px;max-width:700px">
    <input type="hidden" name="id" value="<?= (int)$item['id'] ?>">
    <label>Name (EN)
      <input name="name_en" required maxlength="200" value="<?= htmlspecialchars($item['name_en']) ?>">
    </label>
    <label>Name (AR)
      <input name="name_ar" maxlength="200" value="<?= htmlspecialchars($item['name_ar']) ?>">
    </label>
    <label>Base Price (KD)
      <input type="number" step="0.001" min="0" name="price" required value="<?= htmlspecialchars(number_format((float)$item['price'],3,'.','')) ?>">
    </label>
    <label>Categories (Ctrl/Cmd to multi-select)
      <select name="category_ids[]" multiple size="6">
        <?php foreach ($catsAll as $c): $cid=(int)$c['id']; ?>
          <option value="<?= $cid ?>" <?= in_array($cid,$sel,true)?'selected':'' ?>>
            <?= htmlspecialchars($c['name_en']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Open Price?
      <select name="is_open_price">
        <option value="0" <?= !$item['is_open_price']?'selected':'' ?>>No</option>
        <option value="1" <?= $item['is_open_price']?'selected':'' ?>>Yes</option>
      </select>
    </label>
    <label>Visible on POS?
      <select name="pos_visible">
        <option value="1" <?= $item['pos_visible']?'selected':'' ?>>Visible</option>
        <option value="0" <?= !$item['pos_visible']?'selected':'' ?>>Hidden</option>
      </select>
    </label>
    <label>Status
      <select name="is_active">
        <option value="1" <?= $item['is_active']?'selected':'' ?>>Active</option>
        <option value="0" <?= !$item['is_active']?'selected':'' ?>>Off</option>
      </select>
    </label>
    <div style="display:flex;gap:8px">
      <button class="btn" type="submit">Save</button>
      <a class="btn" href="index.php">Cancel</a>
      <?php if ($editing): ?>
        <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/items/variations.php?product_id='.(int)$item['id'])) ?>">Manage Variations</a>
        <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/items/branch_availability.php?product_id='.(int)$item['id'])) ?>">Branch Availability</a>
      <?php endif; ?>
    </div>
  </form>
</div>
<?php } ?>

<?php require __DIR__ . '/../_footer.php'; ?>