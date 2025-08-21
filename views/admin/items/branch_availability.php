<?php
// views/admin/items/branch_availability.php — branch-level availability & price override
require_once __DIR__ . '/../_header.php';

$tenantId  = tenant_id();
$productId = (int)($_GET['product_id'] ?? 0);

// Load product (tenant scoped)
$pst = db()->prepare("SELECT id, name_en, name_ar, price FROM products WHERE id=:id AND tenant_id=:t LIMIT 1");
$pst->execute([':id' => $productId, ':t' => $tenantId]);
$product = $pst->fetch();

if (!$product) {
  http_response_code(404);
  echo '<div class="card" style="padding:16px">Product not found.</div>';
  require __DIR__ . '/../_footer.php';
  exit;
}

// Branches
$bst = db()->prepare("SELECT id, name, is_active FROM branches WHERE tenant_id = :t ORDER BY name");
$bst->execute([':t' => $tenantId]);
$branches = $bst->fetchAll();

// Current overrides
$ov = db()->prepare("
  SELECT branch_id, is_available, price_override
  FROM product_branch_availability
  WHERE product_id = :pid
");
$ov->execute([':pid' => $productId]);
$current = [];
foreach ($ov->fetchAll() as $row) {
  $current[(int)$row['branch_id']] = [
    'is_available'  => (int)$row['is_available'],
    'price_override'=> $row['price_override'],
  ];
}

// Helpers
function fmt_price($v) {
  if ($v === null || $v === '') return '';
  return number_format((float)$v, 3, '.', '');
}
?>
<div class="card">
  <div class="card-head">
    <h2>Branch Availability — <?= htmlspecialchars($product['name_en']) ?></h2>
    <div style="display:flex;gap:8px;align-items:center">
      <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/items/index.php')) ?>">← Back to Items</a>
    </div>
  </div>

  <div style="padding:12px">
    <p class="muted" style="margin-top:0">
      Base price: <strong>KD <?= number_format((float)$product['price'], 3) ?></strong>.
      Leave <em>Price Override</em> empty to inherit base price in that branch.
      If a branch is <em>not checked</em> and has no override row, it won’t appear on the POS for that branch.
    </p>

    <form method="post" action="<?= htmlspecialchars(base_url('controllers/items/branch_availability_save.php')) ?>">
      <input type="hidden" name="product_id" value="<?= (int)$productId ?>">

      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th style="width:60px">#</th>
              <th>Branch</th>
              <th style="width:140px">Active</th>
              <th style="width:160px">Available?</th>
              <th style="width:200px">Price Override (KD)</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($branches): $i=1; foreach ($branches as $b):
              $bid = (int)$b['id'];
              $row = $current[$bid] ?? null;
              $isAvailable = $row ? (int)$row['is_available'] : 1; // default available if no row
              $priceOverride = $row['price_override'];
            ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($b['name']) ?></td>
                <td><?= (int)$b['is_active'] ? 'Yes' : 'No' ?></td>
                <td>
                  <label style="display:flex;align-items:center;gap:6px">
                    <input type="checkbox" name="available[<?= $bid ?>]" value="1" <?= $isAvailable ? 'checked' : '' ?>>
                    <span>Available</span>
                  </label>
                </td>
                <td>
                  <input
                    type="number" step="0.001" min="0"
                    name="price_override[<?= $bid ?>]"
                    value="<?= htmlspecialchars(fmt_price($priceOverride)) ?>"
                    placeholder="(inherit base)"
                    style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px">
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="5" class="muted">No branches yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div style="margin-top:12px;display:flex;gap:8px">
        <button type="submit" class="btn">Save</button>
        <a class="btn" href="<?= htmlspecialchars(base_url('controllers/items/branch_availability_reset.php?product_id='.(int)$productId)) ?>"
           onclick="return confirm('Reset all overrides for this item?')">Reset Overrides</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>