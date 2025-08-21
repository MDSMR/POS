<?php
// views/admin/customers/show.php — Customer profile with points & ledger
require_once __DIR__ . '/../_header.php';

$tenantId = tenant_id();
$id = (int)($_GET['id'] ?? 0);

// Load customer (tenant scoped)
$st = db()->prepare("
  SELECT id, name, phone, email, points_balance, created_at, updated_at
  FROM customers
  WHERE id=:id AND tenant_id=:t
  LIMIT 1
");
$st->execute([':id'=>$id, ':t'=>$tenantId]);
$c = $st->fetch();

if (!$c) {
  http_response_code(404);
  echo '<div class="card" style="padding:16px">Customer not found.</div>';
  require __DIR__ . '/../_footer.php'; exit;
}

// Loyalty ledger
$ll = db()->prepare("
  SELECT ll.created_at, ll.points_delta, ll.reason, ll.order_id
  FROM loyalty_ledger ll
  WHERE ll.tenant_id=:t AND ll.customer_id=:cid
  ORDER BY ll.id DESC
");
$ll->execute([':t'=>$tenantId, ':cid'=>$id]);
$ledger = $ll->fetchAll();
$sum = array_sum(array_map(fn($r)=> (int)$r['points_delta'], $ledger));
?>
<div class="card">
  <div class="card-head">
    <h2>Customer — <?= htmlspecialchars($c['name']) ?></h2>
    <div style="display:flex;gap:8px;align-items:center">
      <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/orders/index.php')) ?>">← Back</a>
    </div>
  </div>

  <div style="padding:12px;display:grid;gap:10px">
    <div class="grid grid-2">
      <div class="card">
        <div class="card-head"><h2>Profile</h2></div>
        <div style="padding:12px;display:grid;gap:6px">
          <div><strong>Name:</strong> <?= htmlspecialchars($c['name']) ?></div>
          <div><strong>Phone:</strong> <?= htmlspecialchars($c['phone'] ?? '—') ?></div>
          <div><strong>Email:</strong> <?= htmlspecialchars($c['email'] ?? '—') ?></div>
          <div><strong>Points Balance:</strong> <?= (int)$c['points_balance'] ?></div>
          <div class="small muted">Created: <?= htmlspecialchars($c['created_at']) ?> · Updated: <?= htmlspecialchars($c['updated_at'] ?? '') ?></div>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h2>Points Summary</h2></div>
        <div style="padding:12px;display:grid;gap:6px">
          <div><strong>Total (ledger sum):</strong> <?= (int)$sum ?></div>
          <div><strong>Stored balance:</strong> <?= (int)$c['points_balance'] ?></div>
          <div class="small muted">
            Note: Stored balance may differ if points were migrated/adjusted outside the ledger.
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-head"><h2>Loyalty Ledger</h2></div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th style="width:160px">Date</th>
            <th style="text-align:right">Points ±</th>
            <th>Reason</th>
            <th style="width:120px">Order</th>
          </tr></thead>
          <tbody>
            <?php if ($ledger): foreach ($ledger as $r): ?>
              <tr>
                <td><?= htmlspecialchars($r['created_at']) ?></td>
                <td style="text-align:right"><?= (int)$r['points_delta'] ?></td>
                <td><?= htmlspecialchars($r['reason'] ?? '—') ?></td>
                <td>
                  <?php if (!empty($r['order_id'])): ?>
                    <a class="link" href="<?= htmlspecialchars(base_url('views/admin/orders/show.php?id='.(int)$r['order_id'])) ?>">#<?= (int)$r['order_id'] ?></a>
                  <?php else: ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; else: ?>
              <tr><td colspan="4" class="muted">No loyalty activity.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<style>.link{color:#2563eb;text-decoration:none}</style>
<?php require __DIR__ . '/../_footer.php'; ?>