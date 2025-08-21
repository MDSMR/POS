<?php
require_once __DIR__ . '/../_header.php';

$tenantId = tenant_id();

$q = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page-1)*$perPage;

$where = "tenant_id = :t";
$params = [':t'=>$tenantId];

if ($q !== '') {
  $where .= " AND (name LIKE :q OR phone LIKE :q OR email LIKE :q)";
  $params[':q'] = "%$q%";
}

$count = db()->prepare("SELECT COUNT(*) AS c FROM customers WHERE $where");
$count->execute($params);
$total = (int)$count->fetch()['c'];
$pages = max(1, (int)ceil($total/$perPage));

$sql = "SELECT id,name,phone,email,points_balance,created_at
        FROM customers WHERE $where
        ORDER BY created_at DESC
        LIMIT :lim OFFSET :off";
$st = db()->prepare($sql);
foreach ([':lim'=>$perPage, ':off'=>$offset] as $k=>$v) $st->bindValue($k, $v, PDO::PARAM_INT);
foreach ($params as $k=>$v) if (!in_array($k,[':lim',':off'])) $st->bindValue($k, $v);
$st->execute();
$rows = $st->fetchAll();
?>
<div class="card">
  <div class="card-head">
    <h2>Customers</h2>
    <form method="get" style="display:flex;gap:8px;align-items:center">
      <input name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Search name/phone/email…">
      <button class="btn">Search</button>
      <a class="btn" href="index.php">Reset</a>
      <a class="btn" href="index.php?act=new">+ New</a>
    </form>
  </div>

  <div class="table-wrap">
    <table class="table">
      <thead><tr>
        <th style="width:80px">ID</th><th>Name</th><th>Phone</th><th>Email</th>
        <th style="text-align:right">Points</th><th style="width:220px"></th>
      </tr></thead>
      <tbody>
        <?php if ($rows): foreach ($rows as $r): ?>
          <tr>
            <td>#<?= (int)$r['id'] ?></td>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><?= htmlspecialchars($r['phone'] ?? '—') ?></td>
            <td><?= htmlspecialchars($r['email'] ?? '—') ?></td>
            <td style="text-align:right"><?= (int)$r['points_balance'] ?></td>
            <td>
              <a class="btn" href="<?= htmlspecialchars(base_url('views/admin/customers/show.php?id='.(int)$r['id'])) ?>">View</a>
              <a class="btn" href="index.php?act=edit&id=<?= (int)$r['id'] ?>">Edit</a>
              <a class="btn" href="<?= htmlspecialchars(base_url('controllers/customers/delete.php?id='.(int)$r['id'])) ?>" onclick="return confirm('Delete this customer?')">Delete</a>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr><td colspan="6" class="muted">No customers found.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div style="display:flex;justify-content:space-between;align-items:center;padding:10px">
    <div class="small">Page <?= $page ?> of <?= $pages ?> (<?= $total ?> customers)</div>
    <div style="display:flex;gap:6px">
      <?php if ($page>1): ?><a class="btn" href="?<?= http_build_query(['q'=>$q,'page'=>$page-1]) ?>">◀ Prev</a><?php endif; ?>
      <?php if ($page<$pages): ?><a class="btn" href="?<?= http_build_query(['q'=>$q,'page'=>$page+1]) ?>">Next ▶</a><?php endif; ?>
    </div>
  </div>
</div>

<?php
// form
if (($_GET['act']??'')==='new' || (($_GET['act']??'')==='edit' && !empty($_GET['id']))) {
  $editing = ($_GET['act']==='edit');
  $c = ['id'=>0,'name'=>'','phone'=>'','email'=>'','points_balance'=>0];
  if ($editing) {
    $st = db()->prepare("SELECT * FROM customers WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id'=>(int)$_GET['id'], ':t'=>$tenantId]);
    $c = $st->fetch() ?: $c;
  }
?>
<div class="card" style="margin-top:12px">
  <div class="card-head"><h2><?= $editing?'Edit':'New' ?> Customer</h2></div>
  <form method="post" action="<?= htmlspecialchars(base_url('controllers/customers/save.php')) ?>" style="padding:12px;display:grid;gap:10px;max-width:700px">
    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
    <label>Name <input name="name" required maxlength="150" value="<?= htmlspecialchars($c['name']) ?>"></label>
    <label>Phone <input name="phone" maxlength="30" value="<?= htmlspecialchars($c['phone']) ?>"></label>
    <label>Email <input type="email" name="email" maxlength="255" value="<?= htmlspecialchars($c['email']) ?>"></label>
    <label>Points Balance <input type="number" name="points_balance" value="<?= (int)$c['points_balance'] ?>"></label>
    <div style="display:flex;gap:8px">
      <button class="btn">Save</button>
      <a class="btn" href="index.php">Cancel</a>
    </div>
  </form>
</div>
<?php } ?>

<?php require __DIR__ . '/../_footer.php'; ?>