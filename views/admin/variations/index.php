<?php
require_once __DIR__ . '/../_header.php';
$tenantId = tenant_id();

// Groups
$groups = db()->prepare("SELECT id,name,is_required,min_select,max_select,sort_order,is_active
                         FROM variation_groups WHERE tenant_id=:t ORDER BY sort_order,name");
$groups->execute([':t'=>$tenantId]); $groups = $groups->fetchAll();

// If a group is selected, load its values
$gid = (int)($_GET['group_id'] ?? ($groups[0]['id'] ?? 0));
$values = [];
if ($gid) {
  $vs = db()->prepare("SELECT id,value_en,value_ar,price_delta,is_active,sort_order
                       FROM variation_values WHERE group_id=:g ORDER BY sort_order,value_en");
  $vs->execute([':g'=>$gid]); $values = $vs->fetchAll();
}
?>
<div class="grid grid-2">
  <div class="card">
    <div class="card-head">
      <h2>Variation Groups</h2>
      <a class="btn" href="index.php?act=new_group">+ New Group</a>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>#</th><th>Name</th><th>Rules</th><th>Sort</th><th>Status</th><th style="width:160px"></th></tr></thead>
        <tbody>
          <?php if ($groups): $i=1; foreach ($groups as $g): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><a href="index.php?group_id=<?= (int)$g['id'] ?>"><strong><?= htmlspecialchars($g['name']) ?></strong></a></td>
              <td><?= $g['is_required']?'Required':'Optional' ?>, <?= (int)$g['min_select'] ?>–<?= (int)$g['max_select'] ?></td>
              <td><?= (int)$g['sort_order'] ?></td>
              <td><?= $g['is_active']?'Active':'Off' ?></td>
              <td>
                <a class="btn" href="index.php?act=edit_group&id=<?= (int)$g['id'] ?>">Edit</a>
                <a class="btn" href="<?= htmlspecialchars(base_url('controllers/variations/delete_group.php?id='.(int)$g['id'])) ?>" onclick="return confirm('Delete this group?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="6" class="muted">No groups yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="card-head">
      <h2>Values <?= $gid?('(Group #'.(int)$gid.')') : '' ?></h2>
      <?php if ($gid): ?><a class="btn" href="index.php?group_id=<?= (int)$gid ?>&act=new_value">+ New Value</a><?php endif; ?>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>#</th><th>Value (EN)</th><th>Value (AR)</th><th style="text-align:right">Δ Price</th><th>Sort</th><th>Status</th><th style="width:160px"></th></tr></thead>
        <tbody>
          <?php if ($values): $i=1; foreach ($values as $v): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= htmlspecialchars($v['value_en']) ?></td>
              <td><?= htmlspecialchars($v['value_ar']) ?></td>
              <td style="text-align:right"><?= number_format((float)$v['price_delta'],3) ?></td>
              <td><?= (int)$v['sort_order'] ?></td>
              <td><?= $v['is_active']?'Active':'Off' ?></td>
              <td>
                <a class="btn" href="index.php?group_id=<?= (int)$gid ?>&act=edit_value&id=<?= (int)$v['id'] ?>">Edit</a>
                <a class="btn" href="<?= htmlspecialchars(base_url('controllers/variations/delete_value.php?id='.(int)$v['id'].'&group_id='.(int)$gid)) ?>" onclick="return confirm('Delete this value?')">Delete</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="7" class="muted">Select a group to see values.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php
// Group form
if (($_GET['act'] ?? '') === 'new_group' || (($_GET['act'] ?? '') === 'edit_group' && !empty($_GET['id']))) {
  $editing = ($_GET['act'] ?? '') === 'edit_group';
  $g = ['id'=>0,'name'=>'','is_required'=>0,'min_select'=>0,'max_select'=>1,'sort_order'=>0,'is_active'=>1];
  if ($editing) {
    $st = db()->prepare("SELECT * FROM variation_groups WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id'=>(int)$_GET['id'], ':t'=>$tenantId]); $g = $st->fetch() ?: $g;
  }
?>
<div class="card" style="margin-top:12px">
  <div class="card-head"><h2><?= $editing?'Edit':'New' ?> Group</h2></div>
  <form method="post" action="<?= htmlspecialchars(base_url('controllers/variations/save_group.php')) ?>" style="padding:12px;display:grid;gap:10px;max-width:700px">
    <input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
    <label>Name
      <input name="name" required maxlength="120" value="<?= htmlspecialchars($g['name']) ?>">
    </label>
    <label>Required?
      <select name="is_required">
        <option value="0" <?= !$g['is_required']?'selected':'' ?>>No</option>
        <option value="1" <?= $g['is_required']?'selected':'' ?>>Yes</option>
      </select>
    </label>
    <label>Min select <input type="number" name="min_select" value="<?= (int)$g['min_select'] ?>"></label>
    <label>Max select <input type="number" name="max_select" value="<?= (int)$g['max_select'] ?>"></label>
    <label>Sort order <input type="number" name="sort_order" value="<?= (int)$g['sort_order'] ?>"></label>
    <label>Status
      <select name="is_active">
        <option value="1" <?= $g['is_active']?'selected':'' ?>>Active</option>
        <option value="0" <?= !$g['is_active']?'selected':'' ?>>Off</option>
      </select>
    </label>
    <div style="display:flex;gap:8px">
      <button class="btn" type="submit">Save</button>
      <a class="btn" href="index.php">Cancel</a>
    </div>
  </form>
</div>
<?php } ?>

<?php
// Value form
if ($gid && (($_GET['act'] ?? '') === 'new_value' || (($_GET['act'] ?? '') === 'edit_value' && !empty($_GET['id'])))) {
  $editing = ($_GET['act'] ?? '') === 'edit_value';
  $v = ['id'=>0,'value_en'=>'','value_ar'=>'','price_delta'=>'0.000','sort_order'=>0,'is_active'=>1];
  if ($editing) {
    $st = db()->prepare("SELECT * FROM variation_values WHERE id=:id AND group_id=:g LIMIT 1");
    $st->execute([':id'=>(int)$_GET['id'], ':g'=>$gid]); $v = $st->fetch() ?: $v;
  }
?>
<div class="card" style="margin-top:12px">
  <div class="card-head"><h2><?= $editing?'Edit':'New' ?> Value</h2></div>
  <form method="post" action="<?= htmlspecialchars(base_url('controllers/variations/save_value.php')) ?>" style="padding:12px;display:grid;gap:10px;max-width:700px">
    <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
    <input type="hidden" name="group_id" value="<?= (int)$gid ?>">
    <label>Value (EN) <input name="value_en" required maxlength="120" value="<?= htmlspecialchars($v['value_en']) ?>"></label>
    <label>Value (AR) <input name="value_ar" maxlength="120" value="<?= htmlspecialchars($v['value_ar']) ?>"></label>
    <label>Price Δ (KD) <input type="number" step="0.001" name="price_delta" value="<?= htmlspecialchars(number_format((float)$v['price_delta'],3,'.','')) ?>"></label>
    <label>Sort order <input type="number" name="sort_order" value="<?= (int)$v['sort_order'] ?>"></label>
    <label>Status
      <select name="is_active">
        <option value="1" <?= $v['is_active']?'selected':'' ?>>Active</option>
        <option value="0" <?= !$v['is_active']?'selected':'' ?>>Off</option>
      </select>
    </label>
    <div style="display:flex;gap:8px">
      <button class="btn" type="submit">Save</button>
      <a class="btn" href="index.php?group_id=<?= (int)$gid ?>">Cancel</a>
    </div>
  </form>
</div>
<?php } ?>

<?php require __DIR__ . '/../_footer.php'; ?>