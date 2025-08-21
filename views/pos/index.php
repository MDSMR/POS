<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';
pos_auth_require_login();

$posUser = pos_user();
$tenantId = (int)$posUser['tenant_id'];

// Load branches for selector
$bs = db()->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND is_active=1 ORDER BY name");
$bs->execute([':t'=>$tenantId]);
$branches = $bs->fetchAll();
$currentBranch = (int)($_GET['branch_id'] ?? ($branches[0]['id'] ?? 0));

// Load categories & items (visible + active, available in branch)
$cats = db()->prepare("SELECT id,name_en FROM categories WHERE tenant_id=:t AND is_active=1 ORDER BY sort_order,name_en");
$cats->execute([':t'=>$tenantId]); $cats = $cats->fetchAll();

$items = db()->prepare("
  SELECT p.id, p.name_en, p.price,
         COALESCE(pba.price_override, p.price) AS price_eff
  FROM products p
  LEFT JOIN product_branch_availability pba
    ON pba.product_id=p.id AND pba.branch_id=:b
  WHERE p.tenant_id=:t AND p.pos_visible=1 AND p.is_active=1
    AND (pba.is_available=1 OR pba.is_available IS NULL)
  ORDER BY p.name_en
");
$items->execute([':t'=>$tenantId, ':b'=>$currentBranch]);
$itemsAll = $items->fetchAll();

// Map product→categories
$pc = db()->prepare("
  SELECT pc.product_id, c.name_en
  FROM product_categories pc
  JOIN categories c ON c.id=pc.category_id
  WHERE c.tenant_id=:t
");
$pc->execute([':t'=>$tenantId]);
$catsByProduct = [];
foreach ($pc->fetchAll() as $r) $catsByProduct[(int)$r['product_id']][] = $r['name_en'];

// Variation groups per product
$pvg = db()->prepare("
  SELECT pvg.product_id, vg.id, vg.name, vg.is_required, vg.min_select, vg.max_select
  FROM product_variation_groups pvg
  JOIN variation_groups vg ON vg.id=pvg.group_id
  WHERE EXISTS(SELECT 1 FROM products p WHERE p.id=pvg.product_id AND p.tenant_id=:t)
  ORDER BY pvg.sort_order, vg.name
");
$pvg->execute([':t'=>$tenantId]);
$groupsByProduct = [];
foreach ($pvg->fetchAll() as $g) $groupsByProduct[(int)$g['product_id']][] = $g;

// Variation values by group
$vvals = db()->query("SELECT group_id, id, value_en, price_delta FROM variation_values WHERE is_active=1 ORDER BY sort_order,value_en")->fetchAll();
$valuesByGroup = [];
foreach ($vvals as $v) $valuesByGroup[(int)$v['group_id']][] = $v;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= htmlspecialchars(base_url('assets/css/admin.css')) ?>">
<style>
body{background:#f8fafc}
.pos{display:grid;grid-template-columns:280px 1fr 380px;gap:10px;padding:10px}
.sidebar{background:#fff;border:1px solid var(--border);border-radius:12px;padding:10px}
.items{background:#fff;border:1px solid var(--border);border-radius:12px;padding:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:10px;align-content:start}
.item-card{border:1px solid var(--border);border-radius:12px;padding:10px;display:grid;gap:6px;cursor:pointer}
.cart{background:#fff;border:1px solid var(--border);border-radius:12px;padding:10px;display:grid;grid-template-rows:auto 1fr auto;gap:8px}
.cart-list{overflow:auto;border:1px dashed var(--border);border-radius:10px;padding:8px;min-height:140px}
.row{display:flex;justify-content:space-between;align-items:center;gap:8px}
.total{font-size:18px;font-weight:700}
.badge{background:#eef2ff;padding:2px 8px;border-radius:9999px;font-size:12px}
</style>
</head>
<body>
<div style="padding:8px;display:flex;justify-content:space-between;align-items:center;background:#fff;border-bottom:1px solid var(--border)">
  <div><strong>Smorll POS</strong> · Branch:
    <form style="display:inline" method="get">
      <select name="branch_id" onchange="this.form.submit()">
        <?php foreach ($branches as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $currentBranch===(int)$b['id']?'selected':'' ?>>
            <?= htmlspecialchars($b['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
  <div>
    User: <?= htmlspecialchars($posUser['name'] ?? $posUser['username']) ?> (<?= htmlspecialchars($posUser['role_key']) ?>) ·
    <a class="btn" href="<?= htmlspecialchars(base_url('controllers/pos/auth_logout.php')) ?>">Logout</a>
  </div>
</div>

<div class="pos">
  <div class="sidebar">
    <h3>Categories</h3>
    <div style="display:grid;gap:6px">
      <button class="btn" onclick="filterCategory('All')">All</button>
      <?php foreach ($cats as $c): ?>
        <button class="btn" onclick="filterCategory('<?= htmlspecialchars($c['name_en'],ENT_QUOTES,'UTF-8') ?>')">
          <?= htmlspecialchars($c['name_en']) ?>
        </button>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="items" id="itemsGrid">
    <?php foreach ($itemsAll as $it):
      $pid=(int)$it['id']; $price=(float)$it['price_eff'];
      $catText = isset($catsByProduct[$pid]) ? implode(', ', $catsByProduct[$pid]) : '';
    ?>
      <div class="item-card" data-cats="<?= htmlspecialchars($catText) ?>" onclick='addItem(<?= json_encode([$pid,$it["name_en"],$price]) ?>)'>
        <div><strong><?= htmlspecialchars($it['name_en']) ?></strong></div>
        <div class="row">
          <span class="badge"><?= htmlspecialchars($catText ?: '—') ?></span>
          <span>KD <?= number_format($price,3) ?></span>
        </div>
        <?php if (!empty($groupsByProduct[$pid])): ?>
          <div class="small muted">Has options</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="cart">
    <div class="row">
      <h3 style="margin:0">Cart</h3>
      <button class="btn" onclick="clearCart()">Clear</button>
    </div>
    <div class="cart-list" id="cartList"></div>

    <div style="display:grid;gap:8px">
      <div class="row"><span>Subtotal</span><span id="subTotal">KD 0.000</span></div>
      <div class="row"><span>Discount</span><span id="discount">KD 0.000</span></div>
      <div class="row"><span>Tax</span><span id="tax">KD 0.000</span></div>
      <div class="row"><span>Service</span><span id="service">KD 0.000</span></div>
      <div class="row total"><span>Total</span><span id="grandTotal">KD 0.000</span></div>

      <div class="row">
        <label>Table</label>
        <input id="tableNo" style="width:120px" placeholder="e.g. 5">
        <label>Persons</label>
        <input id="guests" style="width:90px" type="number" min="1" value="1">
      </div>
      <div class="row">
        <label>Aggregator</label>
        <select id="aggregator">
          <option value="">— None —</option>
          <?php
            $ags = db()->prepare("SELECT id,name FROM aggregators WHERE tenant_id=:t AND is_active=1 ORDER BY name");
            $ags->execute([':t'=>$tenantId]);
            foreach ($ags->fetchAll() as $a) {
              echo '<option value="'.(int)$a['id'].'">'.htmlspecialchars($a['name']).'</option>';
            }
          ?>
        </select>
      </div>
      <button class="btn" onclick="submitOrder()">Submit Order</button>
    </div>
  </div>
</div>

<script>
const groupsByProduct = <?= json_encode($groupsByProduct) ?>;
const valuesByGroup   = <?= json_encode($valuesByGroup) ?>;
let cart=[];

function filterCategory(name){
  const cards=[...document.querySelectorAll('.item-card')];
  cards.forEach(c=>{
    if(name==='All'){ c.style.display='grid'; return; }
    const cats=c.dataset.cats||'';
    c.style.display = cats.includes(name)?'grid':'none';
  });
}

function addItem([id,name,price]){
  // if product has groups, prompt simple selects
  const g = groupsByProduct[id] || [];
  let opts=[];
  for(const gr of g){
    const vals = valuesByGroup[gr.id] || [];
    let pick = prompt(`${gr.name} (choose one):\n`+vals.map(v=>`${v.id}: ${v.value_en} (+${Number(v.price_delta).toFixed(3)})`).join('\n'));
    if(!pick) continue;
    const sel = vals.find(v=> String(v.id)===String(pick));
    if(sel) opts.push({group:gr.name,value:sel.value_en,delta:parseFloat(sel.price_delta)||0});
  }
  const line = {id,name,price:parseFloat(price),qty:1,opts};
  cart.push(line);
  renderCart();
}

function renderCart(){
  const parent=document.getElementById('cartList');
  parent.innerHTML='';
  let sub=0;
  cart.forEach((l,idx)=>{
    const delta = (l.opts||[]).reduce((a,o)=>a+o.delta,0);
    const unit = l.price + delta;
    const lineTotal = unit*l.qty;
    sub += lineTotal;
    const div=document.createElement('div');
    div.className='row';
    div.innerHTML = `
      <div>
        <strong>${l.name}</strong>
        ${l.opts && l.opts.length ? `<div class="small muted">${l.opts.map(o=>`${o.group}: ${o.value} (+${o.delta.toFixed(3)})`).join(', ')}</div>`:''}
        <div class="small">KD ${unit.toFixed(3)} × 
          <button class="btn" onclick="dec(${idx})">-</button> ${l.qty}
          <button class="btn" onclick="inc(${idx})">+</button>
          <button class="btn" onclick="rem(${idx})">×</button>
        </div>
      </div>
      <div>KD ${lineTotal.toFixed(3)}</div>
    `;
    parent.appendChild(div);
  });
  const tax=0.000, service=0.000, disc=0.000;
  document.getElementById('subTotal').innerText = `KD ${sub.toFixed(3)}`;
  document.getElementById('discount').innerText = `KD ${disc.toFixed(3)}`;
  document.getElementById('tax').innerText = `KD ${tax.toFixed(3)}`;
  document.getElementById('service').innerText = `KD ${service.toFixed(3)}`;
  document.getElementById('grandTotal').innerText = `KD ${(sub - disc + tax + service).toFixed(3)}`;
}
function inc(i){ cart[i].qty++; renderCart(); }
function dec(i){ cart[i].qty=Math.max(1,cart[i].qty-1); renderCart(); }
function rem(i){ cart.splice(i,1); renderCart(); }
function clearCart(){ cart=[]; renderCart(); }

async function submitOrder(){
  if(cart.length===0){ alert('Cart is empty'); return; }
  const payload={
    branch_id: <?= json_encode($currentBranch) ?>,
    table_number: document.getElementById('tableNo').value || null,
    guest_count: parseInt(document.getElementById('guests').value||'1'),
    aggregator_id: document.getElementById('aggregator').value || null,
    items: cart
  };
  // Basic POST to a placeholder endpoint (you can wire to your real API)
  const res = await fetch('<?= htmlspecialchars(base_url('api/orders_create.php')) ?>',{
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  if(res.ok){
    alert('Order submitted');
    clearCart();
  }else{
    alert('Failed to submit order');
  }
}
renderCart();
</script>
</body>
</html>