<?php
// views/admin/product_edit.php — Edit Product (supports multi-value modifiers)
declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

/* Bootstrap + session */
$bootstrap_warning = ''; $bootstrap_ok = false;
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path;
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    } else { $bootstrap_ok = true; }
  } catch (Throwable $e) { $bootstrap_warning = 'Bootstrap error: '.$e->getMessage(); }
  finally { if ($prevHandler){ set_error_handler($prevHandler); } }
}
if ($bootstrap_ok) { try { use_backend_session(); } catch (Throwable $e){ $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: '.$e->getMessage()); } }

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* CSRF */
if (empty($_SESSION['csrf_products'])) { $_SESSION['csrf_products'] = bin2hex(random_bytes(32)); }
$csrf = $_SESSION['csrf_products'];

/* Product ID */
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { $_SESSION['flash'] = 'Product not specified.'; header('Location: /views/admin/products.php'); exit; }

/* Load lists + product */
$categories = $branches = $variation_groups = $variation_values_by_group = [];
$prod = null;
$sel_category_ids = $sel_branch_ids = [];
$sel_mod_pairs = []; // [{group_id, option_id}]
$db_msg = '';

if ($bootstrap_ok) {
  try {
    $pdo = db();

    // Product
    $stmt = $pdo->prepare("
      SELECT id, name_en, name_ar, price, standard_cost, is_open_price,
             weight_kg, calories, prep_time_min,
             is_active, pos_visible, description, description_ar, image_path
      FROM products
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    $prod = $stmt->fetch();
    if (!$prod) { $_SESSION['flash'] = 'Product not found.'; header('Location: /views/admin/products.php'); exit; }

    // Categories
    $categories = $pdo->query("
      SELECT id, name_en AS name
      FROM categories
      WHERE is_active = 1
      ORDER BY sort_order ASC, name_en ASC
    ")->fetchAll() ?: [];

    // Selected categories
    $q = $pdo->prepare("SELECT category_id FROM product_categories WHERE product_id = :p");
    $q->execute([':p' => $id]);
    $sel_category_ids = array_map('intval', array_column($q->fetchAll(), 'category_id'));

    // Branches (prefer name; fallback title)
    try {
      $branches = $pdo->query("SELECT id, name AS name FROM branches ORDER BY name ASC")->fetchAll() ?: [];
      if (!$branches) {
        $branches = $pdo->query("SELECT id, title AS name FROM branches ORDER BY title ASC")->fetchAll() ?: [];
      }
    } catch (Throwable $e) { $branches = []; }
    // Selected branches
    try {
      $q = $pdo->prepare("SELECT branch_id FROM product_branches WHERE product_id = :p");
      $q->execute([':p' => $id]);
      $sel_branch_ids = array_map('intval', array_column($q->fetchAll(), 'branch_id'));
    } catch (Throwable $e) { $sel_branch_ids = []; }

    // Variation groups
    $variation_groups = $pdo->query("
      SELECT id, name
      FROM variation_groups
      WHERE is_active = 1
      ORDER BY sort_order ASC, name ASC
    ")->fetchAll() ?: [];
  } catch (Throwable $e) {
    $db_msg = $e->getMessage();
  }

  // Values by group
  try {
    $vals = $pdo->query("
      SELECT id, group_id, value_en, value_ar, price_delta
      FROM variation_values
      WHERE is_active = 1
      ORDER BY group_id ASC, sort_order ASC, value_en ASC
    ")->fetchAll() ?: [];
    foreach ($vals as $v) {
      $variation_values_by_group[(int)$v['group_id']][] = [
        'id'          => (int)$v['id'],
        'name'        => $v['value_en'],
        'price_delta' => (float)$v['price_delta'],
      ];
    }
  } catch (Throwable $e) {}

  // Product modifiers (pre-selected pairs)
  try {
    $rows = $pdo->prepare("
      SELECT modifier_group_id AS group_id, default_option_id AS option_id
      FROM product_modifiers
      WHERE product_id = :p
      ORDER BY modifier_group_id ASC
    ");
    $rows->execute([':p' => $id]);
    $sel_mod_pairs = array_map(fn($r)=>[
      'group_id'=>(int)$r['group_id'],
      'option_id'=> $r['option_id']!==null ? (int)$r['option_id'] : null
    ], $rows->fetchAll() ?: []);
  } catch (Throwable $e) {}
}

/* Flash */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* Helpers */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<title>Edit Product · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb;--chip:#eef2ff;--chipText:#111827;--danger:#dc2626;--subtle:#f3f4f6}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:1024px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin-bottom:10px}
.h2{font-size:14px;font-weight:800;margin:8px 0 12px;color:#111827}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
@media (max-width:900px){.grid,.grid3{grid-template-columns:1fr}}
.input, textarea{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px}
select{width:100%}
label{font-size:12px;color:var(--muted);display:block;margin-bottom:6px}
.btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:10px 14px;cursor:pointer;text-decoration:none;display:inline-block;color:#111827;line-height:1.1}
.btn:hover{filter:brightness(.98);text-decoration:none}
.btn-primary{background:var(--primary);color:#fff;border-color:#2563eb}
.btn-sm{padding:6px 12px;line-height:1.1}

/* TagSelect (single & multi) */
.tagsel{position:relative;border:1px solid var(--border);border-radius:10px;padding:6px 8px;display:flex;gap:6px;flex-wrap:wrap;min-height:44px;align-items:center;background:#fff}
.tagsel:focus-within{outline:2px solid #c7d2fe;outline-offset:2px}
.tagsel .chip{display:inline-flex;align-items:center;gap:6px;background:var(--chip);color:var(--chipText);border:1px solid #e5e7eb;border-radius:999px;padding:4px 8px;font-size:13px}
.tagsel .chip button{all:unset;cursor:pointer;font-weight:700;line-height:1}
.tagsel input{border:none;outline:none;flex:1 1 120px;min-width:80px;padding:6px 4px}
.tagsel .dropdown{position:relative;width:100%}
.tagsel .menu{position:absolute;left:0;right:0;top:100%;z-index:999;background:#fff;border:1px solid var(--border);border-radius:10px;box-shadow:0 16px 40px rgba(0,0,0,.12);padding:6px;max-height:240px;overflow:auto;display:none}
.tagsel.open .menu{display:block}
.tagsel .opt{padding:8px 10px;border-radius:8px;cursor:pointer}
.tagsel .opt:hover,.tagsel .opt.active{background:var(--subtle)}
.tagsel .empty{padding:8px 10px;color:var(--muted)}

.mod-row{display:grid;grid-template-columns:1fr 1.6fr auto;gap:10px;align-items:end;margin-bottom:10px}
.mod-row .remove{border:1px solid var(--border);border-radius:10px;padding:10px;background:#f3f4f6;cursor:pointer}
.preview{border:1px dashed var(--border);border-radius:10px;padding:10px;display:flex;gap:10px;align-items:center}
.preview img{max-height:60px;border-radius:8px}
.switch{display:flex;align-items:center;gap:10px;border:1px solid var(--border);border-radius:10px;padding:10px;background:#fff}
.small{color:var(--muted);font-size:12px}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
.success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;margin:10px 0}
</style>
</head>
<body>

<?php $active='products'; require __DIR__ . '/../partials/admin_nav.php'; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?><div class="notice"><?= h($bootstrap_warning) ?></div><?php endif; ?>
  <?php if (!empty($flash)): ?><div class="success"><?= h($flash) ?></div><?php endif; ?>
  <?php if ($DEBUG && !empty($db_msg)): ?><div class="small">DEBUG: <?= h($db_msg) ?></div><?php endif; ?>

  <form id="productForm" method="post" action="/controllers/admin/products_save.php" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
    <input type="hidden" name="id" value="<?= h($prod['id']) ?>">
    <div id="hiddenSink" style="display:none"></div>

    <div class="section"><div class="h1">Edit Product</div></div>

    <div class="section">
      <div class="h2">Basics</div>
      <div class="grid">
        <div>
          <label>Name (English)</label>
          <input class="input" name="name_en" required maxlength="200" value="<?= h($prod['name_en']) ?>">
        </div>
        <div>
          <label>Arabic Name (الاسم العربي)</label>
          <input class="input" name="name_ar" maxlength="200" dir="rtl" placeholder="اكتب الاسم هنا" value="<?= h($prod['name_ar']) ?>">
        </div>
      </div>
      <div class="grid">
        <div>
          <label>Description (English)</label>
          <textarea class="input" name="description" rows="4" placeholder="Optional"><?= h($prod['description']) ?></textarea>
        </div>
        <div>
          <label>Arabic Description (الوصف)</label>
          <textarea class="input" name="description_ar" rows="4" dir="rtl" placeholder="اختياري"><?= h($prod['description_ar']) ?></textarea>
        </div>
      </div>
      <div class="grid">
        <div>
          <label>Categories</label>
          <div id="ts-categories" class="tagsel" data-multi="1"></div>
          <div class="small">Search and select multiple categories.</div>
        </div>
        <div>
          <label>Branches</label>
          <div id="ts-branches" class="tagsel" data-multi="1"></div>
          <div class="small">Select one or more branches.</div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="h2">Pricing</div>
      <div class="grid">
        <div>
          <label>Price</label>
          <input id="priceInput" class="input" name="price" inputmode="decimal" placeholder="0.00" value="<?= h(number_format((float)$prod['price'], 2, '.', '')) ?>">
          <div class="small" id="priceHint">Enter the price unless Open Price is enabled.</div>
        </div>
        <div>
          <label>Standard Cost</label>
          <input id="costInput" class="input" name="standard_cost" inputmode="decimal" placeholder="0.00" value="<?= h(number_format((float)$prod['standard_cost'], 2, '.', '')) ?>">
        </div>
      </div>
      <div class="switch" style="margin-top:10px">
        <label style="margin:0"><input id="openPrice" type="checkbox" name="is_open_price" <?= ($prod['is_open_price'] ? 'checked' : '') ?>> Open Price</label>
        <div class="small">If enabled, the POS will ask the cashier to enter the sale price and a short note at checkout.</div>
      </div>
    </div>

    <div class="section">
      <div class="h2">Details</div>
      <div class="grid3">
        <div><label>Weight (kg)</label><input class="input" name="weight_kg" inputmode="decimal" placeholder="e.g., 0.250" value="<?= h($prod['weight_kg']) ?>"></div>
        <div><label>Calories</label><input class="input" name="calories" inputmode="numeric" placeholder="e.g., 420" value="<?= h($prod['calories']) ?>"></div>
        <div><label>Prep time (min)</label><input class="input" name="prep_time_min" inputmode="numeric" placeholder="e.g., 15" value="<?= h($prod['prep_time_min']) ?>"></div>
      </div>
    </div>

    <div class="section">
      <div class="h2">Media</div>
      <?php if (!empty($prod['image_path'])): ?>
        <div class="preview" style="margin-bottom:8px">
          <img src="<?= h($prod['image_path']) ?>" alt="Current Image">
          <span class="small"><?= h(basename($prod['image_path'])) ?></span>
        </div>
      <?php endif; ?>
      <label>Replace Image (JPG/PNG/WebP, max 5 MB)</label>
      <input class="input" type="file" name="image" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
      <div class="preview" id="imgPrev" style="display:none"><img id="imgPrevImg"><span class="small" id="imgPrevName"></span></div>
    </div>

    <div class="section">
      <div class="h2">Modifiers</div>
      <div id="modRows"></div>
      <button class="btn btn-sm" type="button" onclick="addModRow()">+ Add Modifier</button>
      <div class="small" style="margin-top:6px">
        Each row: pick a <strong>Modifier</strong> (single), then edit the <strong>Values</strong> (multiple).  
        When you choose a modifier, all its values are selected by default—you can remove any.
      </div>
    </div>

    <div class="section">
      <div class="h2">Status</div>
      <div class="grid">
        <div><label>Status</label>
          <select class="input" name="is_active">
            <option value="1" <?= ($prod['is_active'] ? 'selected' : '') ?>>Active</option>
            <option value="0" <?= (!$prod['is_active'] ? 'selected' : '') ?>>Inactive</option>
          </select>
        </div>
        <div><label>POS Visibility</label>
          <select class="input" name="pos_visible">
            <option value="1" <?= ($prod['pos_visible'] ? 'selected' : '') ?>>Visible</option>
            <option value="0" <?= (!$prod['pos_visible'] ? 'selected' : '') ?>>Hidden</option>
          </select>
        </div>
      </div>
      <div style="margin-top:12px; display:flex; gap:10px;">
        <a class="btn btn-sm" href="/views/admin/products.php">Back</a>
        <button class="btn btn-primary btn-sm" type="submit">Update</button>
      </div>
    </div>
  </form>
</div>

<script>
/* ===== Data from PHP ===== */
const DATA = {
  categories: <?= json_encode($categories, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  branches:   <?= json_encode($branches, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  varGroups:  <?= json_encode($variation_groups, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  varOpts:    <?= json_encode($variation_values_by_group, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>
};
const SELECTED = {
  categories: <?= json_encode($sel_category_ids, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  branches:   <?= json_encode($sel_branch_ids, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,
  mods:       <?= json_encode($sel_mod_pairs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>
};

/* ===== TagSelect (single & multi) ===== */
function makeTagSelect(root, items, {multi=true, onChange}={}) {
  const state = { multi, selected:new Map(), open:false, idx:-1 };
  root.classList.add('tagsel');
  root.innerHTML = `
    <div class="chips"></div>
    <div class="dropdown">
      <input type="text" placeholder="Type to search…">
      <div class="menu"></div>
    </div>`;
  const chips = root.querySelector('.chips');
  const input = root.querySelector('input');
  const menu  = root.querySelector('.menu');

  function renderChips(){
    chips.innerHTML = '';
    state.selected.forEach(it=>{
      const s=document.createElement('span'); s.className='chip'; s.textContent=it.name;
      const x=document.createElement('button'); x.type='button'; x.textContent='×';
      x.addEventListener('mousedown', e=>{ e.preventDefault(); });
      x.onclick=()=>{ state.selected.delete(it.id); renderChips(); renderMenu(); onChange && onChange(getSelected()); };
      s.appendChild(x);
      chips.appendChild(s);
    });
  }
  function filtered(){
    const q = input.value.trim().toLowerCase();
    return items.filter(it => !state.selected.has(it.id) && (q==='' || (it.name||'').toLowerCase().includes(q)));
  }
  function renderMenu(){
    const list = filtered();
    menu.innerHTML='';
    if (!list.length){ const d=document.createElement('div'); d.className='empty'; d.textContent='No matches'; menu.appendChild(d); return; }
    list.forEach((it,i)=>{
      const r=document.createElement('div'); r.className='opt'+(i===state.idx?' active':'');
      r.textContent = it.name;
      r.addEventListener('mousedown', (e)=>{ e.preventDefault(); select(it); });
      r.addEventListener('touchstart', (e)=>{ e.preventDefault(); select(it); }, {passive:false});
      r.onmouseenter=()=>{ state.idx=i; renderMenu(); };
      menu.appendChild(r);
    });
  }
  function open(){ root.classList.add('open'); state.open=true; state.idx=-1; renderMenu(); }
  function close(){ root.classList.remove('open'); state.open=false; state.idx=-1; }
  function select(it){
    if (!state.multi) state.selected.clear();
    state.selected.set(it.id, it);
    input.value=''; renderChips(); renderMenu();
    if (!state.multi) close();
    onChange && onChange(getSelected());
    input.focus();
  }
  function getSelected(){ return Array.from(state.selected.values()); }

  input.addEventListener('focus', open);
  input.addEventListener('input', ()=>{ open(); renderMenu(); });
  input.addEventListener('keydown', (e)=>{
    const list = filtered();
    if (e.key==='ArrowDown'){ e.preventDefault(); state.idx=Math.min(list.length-1, state.idx+1); renderMenu(); }
    else if (e.key==='ArrowUp'){ e.preventDefault(); state.idx=Math.max(0, state.idx-1); renderMenu(); }
    else if (e.key==='Enter'){ e.preventDefault(); if (state.idx>=0 && list[state.idx]) select(list[state.idx]); }
    else if (e.key==='Backspace' && input.value==='' && state.selected.size){
      const lastId = Array.from(state.selected.keys()).pop();
      state.selected.delete(lastId); renderChips(); renderMenu(); onChange && onChange(getSelected());
    }
  });
  document.addEventListener('mousedown', (e)=>{ if(!root.contains(e.target)) close(); });
  document.addEventListener('touchstart', (e)=>{ if(!root.contains(e.target)) close(); }, {passive:true});

  return {
    getSelected,
    clear(){ state.selected.clear(); renderChips(); renderMenu(); onChange && onChange(getSelected()); },
    setSelected(list){
      state.selected.clear();
      list.forEach(it=>state.selected.set(it.id,it));
      renderChips(); renderMenu(); onChange && onChange(getSelected());
    }
  };
}

/* ===== Instantiate selectors and preselect ===== */
const tsCategories = makeTagSelect(
  document.getElementById('ts-categories'),
  (Array.isArray(DATA.categories)?DATA.categories:[]).map(c=>({id:Number(c.id), name:c.name}))
);
const tsBranches = makeTagSelect(
  document.getElementById('ts-branches'),
  (Array.isArray(DATA.branches)?DATA.branches:[]).map(b=>({id:Number(b.id), name:b.name}))
);

// Preselect
if (Array.isArray(SELECTED.categories) && SELECTED.categories.length){
  const map = new Map((DATA.categories||[]).map(c=>[Number(c.id), c.name]));
  tsCategories.setSelected(SELECTED.categories.filter(id=>map.has(Number(id))).map(id=>({id:Number(id), name:map.get(Number(id))})));
}
if (Array.isArray(SELECTED.branches) && SELECTED.branches.length){
  const map = new Map((DATA.branches||[]).map(b=>[Number(b.id), b.name]));
  tsBranches.setSelected(SELECTED.branches.filter(id=>map.has(Number(id))).map(id=>({id:Number(id), name:map.get(Number(id))})));
}

/* ===== Modifiers: group = single select; values = multi select ===== */
const modRows = document.getElementById('modRows');
let modRowSeq = 0;
function fmtDelta(d){ const n=Number(d||0); return n?` (+${n.toFixed(2)})`:''; }

function addModRow(groupId=null, valueIds=null){
  const rowId = ++modRowSeq;
  const wrap = document.createElement('div');
  wrap.className = 'mod-row';
  wrap.dataset.rowId = String(rowId);
  wrap.innerHTML = `
    <div>
      <label>Modifier</label>
      <div id="ts-modgroup-${rowId}" class="tagsel" data-multi="0"></div>
    </div>
    <div>
      <label>Values</label>
      <div id="ts-modvalues-${rowId}" class="tagsel" data-multi="1"></div>
    </div>
    <div><button type="button" class="remove" onclick="this.closest('.mod-row').remove()">Remove</button></div>
  `;
  modRows.appendChild(wrap);

  const groups = (Array.isArray(DATA.varGroups)?DATA.varGroups:[]).map(g=>({id:Number(g.id), name:g.name}));
  const tsG = makeTagSelect(document.getElementById('ts-modgroup-'+rowId), groups, {
    multi:false,
    onChange:(sel)=>{
      const gId = sel.length ? sel[0].id : null;
      const picks = gId ? (DATA.varOpts[gId] || []) : [];
      const options = picks.map(o=>({id:Number(o.id), name:(o.name + fmtDelta(o.price_delta))}));
      tsV = makeTagSelect(document.getElementById('ts-modvalues-'+rowId), options, {multi:true});
      if (options.length){ tsV.setSelected(options); } // select ALL by default
      wrap.__tsV = tsV;
    }
  });
  let tsV = makeTagSelect(document.getElementById('ts-modvalues-'+rowId), [], {multi:true});

  // Preselect (from server state)
  if (groupId){
    const gObj = groups.find(g=>g.id===Number(groupId));
    if (gObj){ tsG.setSelected([gObj]); }
    const base = (DATA.varOpts[groupId]||[]).map(o=>({id:Number(o.id), name:(o.name+fmtDelta(o.price_delta))}));
    tsV = makeTagSelect(document.getElementById('ts-modvalues-'+rowId), base, {multi:true});
    if (Array.isArray(valueIds) && valueIds.length){
      const mapV = new Map(base.map(v=>[v.id, v]));
      tsV.setSelected(valueIds.filter(v=>mapV.has(Number(v))).map(v=>mapV.get(Number(v))));
    } else {
      tsV.setSelected(base); // default to ALL when none provided
    }
  }
  wrap.__tsG = tsG; wrap.__tsV = tsV;
}

/* Build initial rows from SELECTED.mods (pairs) — group them by group_id */
(function initModifierRowsFromPairs(){
  const pairs = Array.isArray(SELECTED.mods) ? SELECTED.mods : [];
  if (!pairs.length){ addModRow(); return; }
  const grouped = new Map(); // group_id -> Set(option_id)
  for (const p of pairs){
    const gid = Number(p.group_id);
    const oid = p.option_id!=null ? Number(p.option_id) : null;
    if (!grouped.has(gid)) grouped.set(gid, new Set());
    if (oid) grouped.get(gid).add(oid);
  }
  if (grouped.size===0){ addModRow(); return; }
  for (const [gid, set] of grouped.entries()){
    addModRow(gid, Array.from(set.values()));
  }
})();

/* ===== Hidden inputs on submit =====
   For EACH selected value of a group we emit one pair:
   - mod_groups[]  = group_id
   - mod_options[] = value_id
   If a group has no values selected (user removed all), we still send (gid, '') to instruct clearing.
*/
document.getElementById('productForm').addEventListener('submit', function(){
  const sink = document.getElementById('hiddenSink'); sink.innerHTML='';
  tsCategories.getSelected().forEach(it=>{ const i=document.createElement('input'); i.type='hidden'; i.name='categories[]'; i.value=String(it.id); sink.appendChild(i); });
  tsBranches.getSelected().forEach(it=>{ const i=document.createElement('input'); i.type='hidden'; i.name='branches[]'; i.value=String(it.id); sink.appendChild(i); });

  document.querySelectorAll('.mod-row').forEach(row=>{
    const selG = row.__tsG?.getSelected() || [];
    if (!selG.length) return;
    const gid = selG[0].id;
    const selVals = row.__tsV?.getSelected() || [];
    if (selVals.length===0){
      const g=document.createElement('input'); g.type='hidden'; g.name='mod_groups[]'; g.value=String(gid); sink.appendChild(g);
      const o=document.createElement('input'); o.type='hidden'; o.name='mod_options[]'; o.value=''; sink.appendChild(o);
    } else {
      selVals.forEach(v=>{
        const g=document.createElement('input'); g.type='hidden'; g.name='mod_groups[]'; g.value=String(gid); sink.appendChild(g);
        const o=document.createElement('input'); o.type='hidden'; o.name='mod_options[]'; o.value=String(v.id); sink.appendChild(o);
      });
    }
  });
});

/* ===== Image preview ===== */
const imgInput = document.querySelector('input[name="image"]');
if (imgInput){
  imgInput.addEventListener('change', function(){
    const f=this.files && this.files[0];
    const prev=document.getElementById('imgPrev'); const prevImg=document.getElementById('imgPrevImg'); const prevName=document.getElementById('imgPrevName');
    if(!f){ prev.style.display='none'; return; }
    prev.style.display='flex'; prevImg.src=URL.createObjectURL(f); prevName.textContent=f.name+' • '+Math.round(f.size/1024)+' KB';
  });
}

/* ===== Open Price UI (CLEAR then disable) ===== */
const openPriceEl = document.getElementById('openPrice');
const priceInput  = document.getElementById('priceInput');
const costInput   = document.getElementById('costInput');
const priceHint   = document.getElementById('priceHint');
function applyOpenPriceUI(){
  const on = openPriceEl.checked;
  if (on) {
    if (priceInput) priceInput.value = '';
    if (costInput)  costInput.value  = '';
  }
  if (priceInput) priceInput.disabled = on;
  if (costInput)  costInput.disabled  = on;
  if (priceInput) priceInput.placeholder = on ? 'Entered at sale' : '0.00';
  if (priceHint)  priceHint.textContent = on
    ? 'Open Price enabled: the cashier will enter the amount and a brief note on the POS.'
    : 'Enter the price unless Open Price is enabled.';
}
openPriceEl.addEventListener('change', applyOpenPriceUI);
applyOpenPriceUI();
</script>
</body>
</html>