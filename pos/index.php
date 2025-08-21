<?php
// public_html/pos/index.php — Live POS w/ Variations Modal + Discount/Promo
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

$posUser  = pos_user();
$tenantId = (int)$posUser['tenant_id'];

// Optional branch selector (best-effort)
$branches = [];
$BR_ERR = '';
try {
  require_once __DIR__ . '/../config/db.php';
  if (!function_exists('db') && isset($pdo) && $pdo instanceof PDO) {
    function db(): PDO { global $pdo; return $pdo; }
  }
  if (function_exists('db')) {
    $bs = db()->prepare("SELECT id,name FROM branches WHERE tenant_id=:t AND is_active=1 ORDER BY name");
    $bs->execute([':t'=>$tenantId]);
    $branches = $bs->fetchAll();
  }
} catch (Throwable $e) { $BR_ERR = $e->getMessage(); }

$currentBranch = (int)($_GET['branch_id'] ?? ($branches[0]['id'] ?? 1));
if ($currentBranch <= 0) $currentBranch = 1;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --border:#e5e7eb; --muted:#6b7280; --bg:#f8fafc; --card:#ffffff; --ink:#0f172a;
  --brand:#111827; --accent:#2563eb; --warn:#fef3c7; --warnb:#fde68a; --good:#eafff4; --goodb:#bcf7d0;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:system-ui,-apple-system,Segoe UI,Roboto}
a{text-decoration:none}
.top{position:sticky;top:0;z-index:50;display:flex;gap:10px;justify-content:space-between;align-items:center;padding:10px;background:#fff;border-bottom:1px solid var(--border)}
.btn{padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:var(--brand);color:#fff;cursor:pointer}
.btn.secondary{background:#fff;color:#111;border-color:var(--border)}
.btn.ghost{background:transparent;color:var(--ink);border-color:var(--border)}
.badge{display:inline-block;padding:2px 8px;border:1px solid var(--border);border-radius:999px;font-size:12px;background:#fff}
.wrap{padding:10px;display:grid;grid-template-columns:1fr 420px;gap:12px}
@media (max-width: 1100px){ .wrap{grid-template-columns:1fr} }
.card{background:var(--card);border:1px solid var(--border);border-radius:12px}
.card-head{display:flex;justify-content:space-between;align-items:center;padding:12px 12px;border-bottom:1px solid var(--border);gap:8px}
.card-body{padding:12px}
.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
@media (max-width: 1200px){ .grid{grid-template-columns:repeat(3,1fr)} }
@media (max-width: 900px){ .grid{grid-template-columns:repeat(2,1fr)} }
.item{border:1px solid var(--border);border-radius:10px;padding:10px;display:grid;gap:6px}
.item h4{margin:0;font-size:16px}
.price{font-weight:700}
.muted{color:var(--muted);font-size:12px}
.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.select, select, input[type="text"], input[type="number"], input[type="date"]{
  padding:8px;border:1px solid var(--border);border-radius:8px;font-size:14px;background:#fff
}
.table{width:100%;border-collapse:separate;border-spacing:0 6px}
.table th{font-size:12px;text-align:left;color:var(--muted);padding:4px 6px}
.table td{background:#fff;border:1px solid var(--border);border-left-width:0;border-right-width:0;padding:8px 6px}
.table tr td:first-child{border-left-width:1px;border-top-left-radius:10px;border-bottom-left-radius:10px}
.table tr td:last-child{border-right-width:1px;border-top-right-radius:10px;border-bottom-right-radius:10px}
.line-actions{display:flex;gap:6px;justify-content:flex-end}
.warn{color:#92400e;background:var(--warn);border:1px solid var(--warnb);border-radius:8px;padding:8px}
.good{color:#065f46;background:var(--good);border:1px solid var(--goodb);border-radius:8px;padding:8px}
.total{font-size:18px;font-weight:800}
.hr{height:1px;background:var(--border);margin:10px 0}
.small{font-size:12px;color:var(--muted)}
.tag{display:inline-block;background:#eef2ff;border:1px solid #e0e7ff;border-radius:999px;padding:2px 8px;font-size:12px}

/* Modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);display:none;align-items:center;justify-content:center;padding:16px}
.modal{max-width:560px;width:100%;background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:0 20px 60px rgba(0,0,0,.25)}
.modal-head{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.modal-body{padding:14px;max-height:70vh;overflow:auto}
.group{border:1px dashed var(--border);border-radius:10px;padding:10px;margin-bottom:10px}
.group h4{margin:0 0 6px 0}
.group .hint{font-size:12px;color:var(--muted)}
.opts{display:flex;flex-wrap:wrap;gap:8px;margin-top:8px}
.opt{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:999px;padding:6px 10px;cursor:pointer}
.modal-foot{padding:12px 14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
</style>
</head>
<body>
  <div class="top">
    <div style="display:flex;align-items:center;gap:8px">
      <strong>Smorll POS</strong>
      <span class="badge"><?= htmlspecialchars($posUser['name'] ?? $posUser['username']) ?> • <?= htmlspecialchars($posUser['role_key']) ?></span>
    </div>
    <div class="row">
      <form method="get" class="row" style="gap:6px">
        <?php if ($branches): ?>
          <select name="branch_id" onchange="this.form.submit()">
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= $currentBranch===(int)$b['id']?'selected':'' ?>>
                <?= htmlspecialchars($b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        <?php else: ?>
          <input class="select" name="branch_id" value="<?= (int)$currentBranch ?>" size="4">
          <button class="btn secondary">Set Branch</button>
        <?php endif; ?>
      </form>
      <a class="btn ghost" href="<?= htmlspecialchars(base_url('controllers/pos/auth_logout.php')) ?>">Logout</a>
    </div>
  </div>

  <div class="wrap">
    <!-- LEFT: Items -->
    <div class="card">
      <div class="card-head">
        <div class="row">
          <input id="search" class="select" placeholder="Search items…">
          <select id="category" class="select"><option value="">All categories</option></select>
        </div>
        <div class="row small">
          <span id="settingsBadge" class="tag">Tax 0% • Service 0%</span>
        </div>
      </div>
      <div class="card-body">
        <?php if ($BR_ERR): ?>
          <div class="warn">Branch list error: <?= htmlspecialchars($BR_ERR) ?>. You can still use the POS with manual branch id.</div>
        <?php endif; ?>
        <div id="items" class="grid"></div>
        <div id="itemsEmpty" class="small" style="display:none;margin-top:6px">No products found.</div>
      </div>
    </div>

    <!-- RIGHT: Cart -->
    <div class="card">
      <div class="card-head">
        <strong>Cart</strong>
        <div class="row">
          <select id="aggregator" class="select">
            <option value="">Walk-in / Normal</option>
            <option value="1">Talabat</option>
          </select>
        </div>
      </div>
      <div class="card-body">
        <table class="table" id="cartTable">
          <thead>
            <tr>
              <th>Item</th>
              <th style="width:72px">Qty</th>
              <th style="width:110px;text-align:right">Price</th>
              <th style="width:110px;text-align:right">Line</th>
              <th style="width:90px"></th>
            </tr>
          </thead>
          <tbody id="cartBody"></tbody>
        </table>

        <div class="hr"></div>

        <div class="row" style="justify-content:space-between;align-items:flex-start;gap:10px">
          <div class="row" style="gap:6px;align-items:center">
            <input class="select" id="tableNumber" placeholder="Table #" style="width:100px">
            <input class="select" id="guestCount" type="number" min="0" placeholder="Guests" style="width:110px">
          </div>
          <div class="row" style="gap:6px;align-items:center">
            <!-- Manual discount -->
            <select id="discType" class="select" title="Discount type">
              <option value="">No discount</option>
              <option value="percent">% off</option>
              <option value="fixed">Fixed KD</option>
            </select>
            <input id="discValue" class="select" type="number" min="0" step="0.001" placeholder="Value" style="width:110px">
            <!-- Promo code -->
            <input id="promoCode" class="select" placeholder="Promo code" style="width:140px">
            <button id="applyPromo" class="btn secondary" type="button">Apply</button>
          </div>
        </div>

        <div id="promoMsg" class="small" style="margin-top:6px"></div>

        <div class="hr"></div>
        <div class="row" style="justify-content:space-between">
          <div class="small" id="totalsNote">Subtotal • Discount • Tax • Service</div>
          <div class="total" id="totalLabel">KD 0.000</div>
        </div>

        <div class="hr"></div>
        <div class="row" style="justify-content:flex-end;gap:8px">
          <button id="clearCart" class="btn secondary" type="button">Clear</button>
          <button id="submitOrder" class="btn" type="button">Submit Order</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Variations Modal -->
  <div id="modal" class="modal-backdrop">
    <div class="modal">
      <div class="modal-head">
        <div><strong id="modalTitle">Customize</strong></div>
        <button class="btn secondary" type="button" onclick="closeModal()">Close</button>
      </div>
      <div class="modal-body" id="modalBody"></div>
      <div class="modal-foot">
        <div class="small" id="modalHint">Select required options</div>
        <div class="row">
          <button class="btn secondary" type="button" onclick="closeModal()">Cancel</button>
          <button class="btn" id="modalAddBtn" type="button">Add to cart</button>
        </div>
      </div>
    </div>
  </div>

<script>
const state = {
  branchId: <?= (int)$currentBranch ?>,
  taxPercent: 0,
  servicePercent: 0,
  products: [],
  groupsByProduct: {},
  valuesByGroup: {},
  cart: [], // {id, name, price, qty, opts:[{group,value,delta}]}
  manualDiscount: {type:'', value:0},
  promoCode: '',
  promoAccepted: null // {discount_rule_id, promo_code_id, amount_type, amount_value}
};

function fmt(n){ return 'KD ' + Number(n).toFixed(3); }
function el(tag, attrs={}, ...kids){
  const e = document.createElement(tag);
  Object.entries(attrs).forEach(([k,v])=>{
    if (k==='class') e.className=v; else if (k==='style') e.style.cssText=v;
    else if (k.startsWith('on')) e[k] = v; else e.setAttribute(k,v);
  });
  kids.forEach(k=>{ if(typeof k==='string') e.appendChild(document.createTextNode(k)); else if(k) e.appendChild(k); });
  return e;
}

async function loadMe(){
  const r = await fetch('<?= htmlspecialchars(base_url('api/me.php')) ?>');
  const j = await r.json();
  if(!j.ok){ alert('Session expired. Please login again.'); location.href='<?= htmlspecialchars(base_url('pos/login.php')) ?>'; return; }
  state.taxPercent = Number(j.settings?.tax_percent || 0);
  state.servicePercent = Number(j.settings?.service_percent || 0);
  document.getElementById('settingsBadge').textContent = `Tax ${state.taxPercent}% • Service ${state.servicePercent}%`;
}

async function loadItems(){
  const q = document.getElementById('search').value.trim();
  const cat = document.getElementById('category').value;
  const url = new URL('<?= htmlspecialchars(base_url('api/items.php')) ?>', location.origin);
  url.searchParams.set('branch_id', state.branchId);
  if(q) url.searchParams.set('q', q);
  if(cat) url.searchParams.set('category_id', cat);
  url.searchParams.set('only_visible','1');

  const r = await fetch(url.toString());
  const j = await r.json();
  if(!j.ok){ alert(j.error || 'Failed to load items'); return; }

  state.products = j.products || [];
  state.groupsByProduct = j.groups_by_product || {};
  state.valuesByGroup = j.values_by_group || {};

  // categories dropdown
  const catSel = document.getElementById('category');
  const keep = catSel.value;
  catSel.innerHTML = '<option value="">All categories</option>';
  (j.categories||[]).forEach(c=>{
    const o = el('option', {value:c.id}, c.name_en);
    catSel.appendChild(o);
  });
  if(keep) catSel.value = keep;

  renderItems();
}

function renderItems(){
  const cont = document.getElementById('items');
  const empty = document.getElementById('itemsEmpty');
  cont.innerHTML = '';
  if(!state.products.length){ empty.style.display='block'; return; }
  empty.style.display='none';

  state.products.forEach(p=>{
    const btn = el('button', {class:'btn', onclick: ()=> onAddClick(p)}, 'Add');
    const cats = (p.categories||[]).join(', ');
    cont.appendChild(
      el('div', {class:'item'},
        el('h4', {}, p.name_en),
        el('div', {class:'muted'}, cats || '—'),
        el('div', {class:'row'},
          el('div', {class:'price'}, fmt(p.price_eff)),
          p.has_options ? el('span',{class:'badge'},'Options') : null
        ),
        btn
      )
    );
  });
}

function onAddClick(p){
  const groups = state.groupsByProduct[p.id];
  if(!groups || !groups.length){
    // no options → add directly
    state.cart.push({ id:p.id, name:p.name_en, price:Number(p.price_eff), qty:1, opts:[] });
    renderCart();
    return;
  }
  openVariationsModal(p, groups);
}

// Modal control
function openVariationsModal(product, groups){
  const md = document.getElementById('modal');
  const body = document.getElementById('modalBody');
  const title = document.getElementById('modalTitle');
  title.textContent = `Customize: ${product.name_en}`;
  body.innerHTML = '';
  md.style.display='flex';

  // Build groups
  groups.forEach(g=>{
    const vals = state.valuesByGroup[g.id] || [];
    const hint = `Required: ${g.is_required ? 'Yes' : 'No'} • Min ${g.min_select} • Max ${g.max_select}`;
    const box = el('div', {class:'group'},
      el('h4', {}, g.name),
      el('div', {class:'hint'}, hint),
      el('div', {class:'opts', id:`opts-${g.id}`})
    );
    body.appendChild(box);

    const multiselect = (g.max_select > 1);
    vals.forEach(v=>{
      const id = `g${g.id}-v${v.id}`;
      const inp = el('input', {type: multiselect? 'checkbox':'radio', name:`grp-${g.id}`, id, value:v.id});
      const lab = el('label', {for:id, class:'opt'}, `${v.value_en}${Number(v.price_delta)?' (+'+Number(v.price_delta).toFixed(3)+')':''}`);
      const wrap = el('div', {class:'opt'}, inp, lab);
      document.getElementById(`opts-${g.id}`).appendChild(wrap);
    });
  });

  // Live validation + preview
  const hint = document.getElementById('modalHint');
  function computePreview(){
    let ok = true;
    let msg = [];
    let delta = 0;

    groups.forEach(g=>{
      const sel = Array.from(document.querySelectorAll(`[name="grp-${g.id}"]`))
        .filter(x=>x.checked)
        .map(x=>Number(x.value));
      if (g.is_required && sel.length === 0) { ok = false; msg.push(`${g.name}: at least ${Math.max(1,g.min_select)}`); }
      if (g.min_select && sel.length < g.min_select) { ok = false; msg.push(`${g.name}: min ${g.min_select}`); }
      if (g.max_select && sel.length > g.max_select) { ok = false; msg.push(`${g.name}: max ${g.max_select}`); }
      sel.forEach(vid=>{
        const v = (state.valuesByGroup[g.id]||[]).find(z=>z.id===vid);
        if(v) delta += Number(v.price_delta||0);
      });
    });

    hint.textContent = ok ? `Price delta +${delta.toFixed(3)}` : ('Please fix: '+msg.join('; '));
    return {ok, delta};
  }
  body.addEventListener('change', computePreview, {once:false, passive:true});
  computePreview();

  // Add to cart
  const addBtn = document.getElementById('modalAddBtn');
  addBtn.onclick = ()=>{
    const chk = computePreview();
    if(!chk.ok) return;

    const chosen = [];
    groups.forEach(g=>{
      const sel = Array.from(document.querySelectorAll(`[name="grp-${g.id}"]`)).filter(x=>x.checked).map(x=>Number(x.value));
      sel.forEach(vid=>{
        const v = (state.valuesByGroup[g.id]||[]).find(z=>z.id===vid);
        if(v) chosen.push({group:g.name, value:v.value_en, delta:Number(v.price_delta||0)});
      });
    });

    state.cart.push({ id:product.id, name:product.name_en, price:Number(product.price_eff), qty:1, opts:chosen });
    closeModal();
    renderCart();
  };
}
function closeModal(){ document.getElementById('modal').style.display='none'; }

function renderCart(){
  const tb = document.getElementById('cartBody');
  tb.innerHTML = '';
  let subtotal = 0;

  state.cart.forEach((L, idx)=>{
    const delta = (L.opts||[]).reduce((s,o)=>s + Number(o.delta||0), 0);
    const unit = Number(L.price) + delta;
    const line = unit * Number(L.qty||1);
    subtotal += line;

    tb.appendChild(el('tr', {},
      el('td', {},
        el('div', {}, L.name),
        (L.opts && L.opts.length)
          ? el('div', {class:'small'}, L.opts.map(o=>`${o.group}: ${o.value}${o.delta?` (+${o.delta})`:''}`).join(' · '))
          : el('div', {class:'small muted'}, 'No options')
      ),
      el('td', {},
        el('div', {class:'row'},
          el('button', {class:'btn secondary', onclick:()=>{ if(L.qty>1){ L.qty--; renderCart(); } }}, '−'),
          el('input', {type:'number', value:L.qty, min:'1', style:'width:56px', oninput:(e)=>{ L.qty = Math.max(1, parseInt(e.target.value||1)); renderCart(); }}),
          el('button', {class:'btn secondary', onclick:()=>{ L.qty++; renderCart(); }}, '+')
        )
      ),
      el('td', {style:'text-align:right'}, fmt(unit)),
      el('td', {style:'text-align:right'}, fmt(line)),
      el('td', {},
        el('div', {class:'line-actions'},
          el('button', {class:'btn ghost', onclick:()=>{ state.cart.splice(idx,1); renderCart(); }}, 'Remove')
        )
      )
    ));
  });

  // Manual discount widget → note only affects preview; server is source of truth
  const type = document.getElementById('discType').value;
  const val  = Number(document.getElementById('discValue').value || 0);
  state.manualDiscount = {type, value: val>0?val:0};

  let manualDisc = 0;
  if (type==='percent' && val>0) manualDisc = subtotal * (val/100);
  if (type==='fixed'   && val>0) manualDisc = val;
  manualDisc = Math.min(manualDisc, subtotal);
  const afterDisc = subtotal - manualDisc;

  const tax = afterDisc * (state.taxPercent/100);
  const svc = afterDisc * (state.servicePercent/100);
  const total = afterDisc + tax + svc;

  document.getElementById('totalLabel').textContent = fmt(total);
  document.getElementById('totalsNote').textContent = `Sub ${fmt(subtotal)} • Disc ${fmt(manualDisc)} • Tax ${fmt(tax)} • Service ${fmt(svc)}`;
}

async function submitOrder(){
  if(!state.cart.length){ alert('Cart is empty'); return; }

  const tableNumber = document.getElementById('tableNumber').value.trim();
  const guestCount  = Number(document.getElementById('guestCount').value || 0);
  const aggIdStr = document.getElementById('aggregator').value;
  const aggregator_id = aggIdStr ? Number(aggIdStr) : null;

  const items = state.cart.map(L=>({
    id: L.id,
    qty: Number(L.qty || 1),
    opts: (L.opts||[]).map(o=>({group:o.group, value:o.value}))
  }));

  // Build discounts payload
  const discounts = {};
  if (state.manualDiscount.type && state.manualDiscount.value>0) {
    discounts.manual_discount = {
      type: state.manualDiscount.type, // 'percent' | 'fixed'
      value: Number(state.manualDiscount.value)
    };
  }
  if (state.promoAccepted || state.promoCode) {
    discounts.promo_code = state.promoAccepted?.code || state.promoCode || '';
  }

  const payload = {
    branch_id: state.branchId,
    table_number: tableNumber || undefined,
    guest_count: guestCount || undefined,
    aggregator_id,
    items,
    discounts
  };

  const r = await fetch('<?= htmlspecialchars(base_url('api/orders_create.php')) ?>', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify(payload)
  });
  const j = await r.json().catch(()=>({ok:false,error:'Invalid server response'}));
  if(!j.ok){ alert(j.error || 'Order failed'); return; }

  alert('Order submitted (#'+j.order_id+')');
  state.cart = [];
  state.promoAccepted = null;
  document.getElementById('promoCode').value = '';
  document.getElementById('promoMsg').innerHTML = '';
  renderCart();
}

function debounce(fn, ms){ let t; return (...a)=>{ clearTimeout(t); t=setTimeout(()=>fn(...a), ms); }; }

document.getElementById('submitOrder').addEventListener('click', submitOrder);
document.getElementById('clearCart').addEventListener('click', ()=>{ state.cart=[]; renderCart(); });
document.getElementById('search').addEventListener('input', debounce(loadItems, 250));
document.getElementById('category').addEventListener('change', loadItems);
document.getElementById('discType').addEventListener('change', renderCart);
document.getElementById('discValue').addEventListener('input', renderCart);

document.getElementById('applyPromo').addEventListener('click', ()=>{
  const code = document.getElementById('promoCode').value.trim();
  if(!code){ state.promoAccepted=null; document.getElementById('promoMsg').innerHTML=''; renderCart(); return; }
  // UI-only acceptance; the server will validate for real when creating the order
  state.promoAccepted = {code};
  document.getElementById('promoMsg').innerHTML = '<div class="good">Promo code staged: <b>'+escapeHtml(code)+'</b> (will be validated on submit)</div>';
  renderCart();
});

function escapeHtml(s){ return s.replace(/[&<>"']/g, m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;' }[m])); }

(async function init(){
  await loadMe();
  await loadItems();
  renderCart();
})();
</script>
</body>
</html>