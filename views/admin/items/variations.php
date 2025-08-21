<?php
// views/admin/items/variations.php — attach/detach/sort variation groups for a product
require_once __DIR__ . '/../_header.php';

$tenantId = tenant_id();
$productId = (int)($_GET['product_id'] ?? 0);

// Load product (tenant scoped)
$pst = db()->prepare("SELECT id, name_en, name_ar FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
$pst->execute([':id'=>$productId, ':t'=>$tenantId]);
$product = $pst->fetch();

if (!$product) {
  http_response_code(404);
  echo '<div class="card" style="padding:16px">Product not found.</div>';
  require __DIR__ . '/../_footer.php'; exit;
}

// Attached groups
$gst = db()->prepare("
  SELECT vg.id, vg.name, vg.is_required, vg.min_select, vg.max_select, pvg.sort_order
  FROM product_variation_groups pvg
  JOIN variation_groups vg ON vg.id = pvg.group_id
  WHERE pvg.product_id = :pid
  ORDER BY pvg.sort_order, vg.name
");
$gst->execute([':pid'=>$productId]);
$attached = $gst->fetchAll();

// Available groups (tenant scoped, not attached)
$ast = db()->prepare("
  SELECT vg.id, vg.name, vg.is_required, vg.min_select, vg.max_select
  FROM variation_groups vg
  WHERE vg.tenant_id = :t
    AND NOT EXISTS (
      SELECT 1 FROM product_variation_groups pvg
      WHERE pvg.product_id = :pid AND pvg.group_id = vg.id
    )
  ORDER BY vg.sort_order, vg.name
");
$ast->execute([':t'=>$tenantId, ':pid'=>$productId]);
$available = $ast->fetchAll();
?>
<div class="card">
  <div class="card-head">
    <h2>Item Variations — <?= htmlspecialchars($product['name_en']) ?></h2>
    <div style="display:flex;gap:8px;align-items:center">
      <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/items/index.php')) ?>">← Back to Items</a>
      <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/variations/index.php')) ?>">Manage Groups</a>
    </div>
  </div>

  <div style="padding:12px">
    <h3 style="margin:0 0 8px">Attached Groups</h3>
    <form method="post" action="<?= htmlspecialchars(base_url('controllers/items/pvg_save_order.php')) ?>">
      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:60px">#</th>
              <th>Group</th>
              <th>Rules</th>
              <th style="width:140px">Sort Order</th>
              <th style="width:140px"></th>
            </tr>
          </thead>
          <tbody>
          <?php if ($attached): $i=1; foreach ($attached as $g): ?>
            <tr>
              <td><?= $i++ ?></td>
              <td><?= htmlspecialchars($g['name']) ?></td>
              <td>
                <?= $g['is_required'] ? 'Required' : 'Optional' ?>,
                <?= (int)$g['min_select'] ?>–<?= (int)$g['max_select'] ?>
              </td>
              <td>
                <input type="number" name="sort_order[<?= (int)$g['id'] ?>]" value="<?= (int)$g['sort_order'] ?>"
                       style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px">
              </td>
              <td>
                <a class="btn" href="<?= htmlspecialchars(base_url('controllers/items/pvg_detach.php?product_id='.(int)$productId.'&group_id='.(int)$g['id'])) ?>"
                   onclick="return confirm('Detach this group from the item?')">Detach</a>
              </td>
            </tr>
          <?php endforeach; else: ?>
            <tr><td colspan="5" class="muted">No groups attached yet.</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
      <div style="margin-top:10px;display:flex;gap:8px">
        <button class="btn" type="submit">Save Order</button>
        <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/items/index.php')) ?>">Cancel</a>
      </div>
    </form>
  </div>

  <div style="padding:12px;border-top:1px solid var(--border)">
    <h3 style="margin:0 0 8px">Attach a Group</h3>
    <form method="post" action="<?= htmlspecialchars(base_url('controllers/items/pvg_attach.php')) ?>" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">
      <select name="group_id" required style="padding:8px 10px;border:1px solid var(--border);border-radius:8px;min-width:260px">
        <option value="">— Select group —</option>
        <?php foreach ($available as $g): ?>
          <option value="<?= (int)$g['id'] ?>">
            <?= htmlspecialchars($g['name']) ?> (<?= $g['is_required']?'Req':'Opt' ?>, <?= (int)$g['min_select'] ?>–<?= (int)$g['max_select'] ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <button class="btn" type="submit">Attach</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>