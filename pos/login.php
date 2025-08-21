<?php
// public_html/pos/login.php — POS PIN login form (no DB includes here)
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_session_start();

// If already logged in to POS, go straight to POS home:
if (pos_user()) {
  header('Location: ' . base_url('pos/index.php'));
  exit;
}

$error = $_GET['e'] ?? '';
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Smorll POS · Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
:root{
  --bg2:#f7f8fa;
  --card:#ffffffc9;
  --panel:#ffffffee;
  --text:#111827;
  --muted:#6b7280;
  --primary:#2563eb;
  --primary-2:#60a5fa;
  --accent-red:#e11d48;
  --border:#e5e7eb;
  --ring:#93c5fd;
  --shadow-sm:0 6px 18px rgba(0,0,0,.06);
  --shadow-md:0 16px 40px rgba(0,0,0,.12);
  --shadow-lg:0 30px 70px rgba(0,0,0,.18);
}

/* Moonshine font */
@font-face{
  font-family:"Moonshine";
  src: url("<?= htmlspecialchars(base_url('assets/fonts/Moonshine.woff2')) ?>") format("woff2"),
       url("<?= htmlspecialchars(base_url('assets/fonts/Moonshine.woff')) ?>") format("woff");
  font-display: swap;
}
.moonshine{ font-family:"Moonshine", "Moonshine Regular", cursive; }

/* Base layout */
*{box-sizing:border-box}
html,body{height:100%}
body{
  margin:0;
  color:var(--text);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Helvetica,Arial;
  background:
    radial-gradient(1100px 520px at -10% 0%, #e0e7ff 0%, transparent 60%),
    radial-gradient(900px 500px at 110% 0%, #dbeafe 0%, transparent 60%),
    linear-gradient(180deg, var(--bg2), #ffffff);
  display:grid; place-items:center; padding:24px;
}

/* Card shell */
.card{
  width:min(960px, 96vw);
  background:var(--card);
  backdrop-filter:blur(10px);
  -webkit-backdrop-filter:blur(10px);
  border:1px solid rgba(229,231,235,.65);
  border-radius:22px;
  box-shadow:var(--shadow-md);
  overflow:hidden;
  transition:transform .25s ease, box-shadow .25s ease;
}
.card:hover{ transform:translateY(-2px); box-shadow:var(--shadow-lg); }

.card-grid{
  display:grid;
  grid-template-columns:1.1fr .9fr;
}
@media (max-width:920px){
  .card-grid{ grid-template-columns:1fr; }
}

/* ===== LEFT: Big Moonshine “Smorll” ===== */
.left{
  min-height:320px;
  padding:28px;
  display:grid;
  place-items:center;
  background:
    radial-gradient(800px 420px at 0% 10%, #eff6ff 0%, transparent 60%),
    linear-gradient(135deg, #ffffffaa, #f3f4f6aa);
  border-right:1px solid rgba(229,231,235,.6);
}
.brand-only{
  display:grid; gap:10px; justify-items:center; text-align:center;
}
.brand-monogram{
  font-size:96px;
  line-height:1;
  color:var(--accent-red);
}

/* ===== RIGHT: Form stack ===== */
.right{ padding:26px 22px; display:grid; gap:16px; }

/* Error */
.error{
  color:#7f1d1d;
  background:#fee2e2cc;
  border:1px solid #fecaca;
  border-radius:12px;
  padding:10px 12px;
}

/* User selection */
.box{
  border:1px solid var(--border);
  border-radius:16px;
  background:var(--panel);
  padding:16px;
  box-shadow:var(--shadow-sm);
}
.section-title{ text-align:center; font-weight:700; color:#1f2937; margin:2px 0 12px; }

.user-grid-scroll{ max-height:360px; overflow-y:auto; padding-right:6px; }
.user-grid-scroll::-webkit-scrollbar{ width:8px }
.user-grid-scroll::-webkit-scrollbar-thumb{ background:#d1d5db; border-radius:999px }
.user-grid{ display:grid; gap:12px; grid-template-columns:repeat(2,1fr); }
@media (min-width:560px){ .user-grid{ grid-template-columns:repeat(3,1fr); } }

.user-tile{
  position:relative;
  padding:14px;
  border:1px solid var(--border);
  border-radius:14px;
  background:linear-gradient(180deg,#ffffff,#f8fbff);
  display:grid; gap:10px; justify-items:center;
  cursor:pointer;
  transition:transform .12s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease;
  overflow:hidden;
  text-align:center;
}
.user-tile:hover{ transform:translateY(-1px); box-shadow:0 12px 30px rgba(0,0,0,.1) }
.user-tile.selected{ border-color:var(--ring); box-shadow:0 0 0 3px rgba(147,197,253,.35), var(--shadow-sm); background:#f0f7ff; }

.avatar{
  width:56px; height:56px; border-radius:50%;
  display:grid; place-items:center;
  font-weight:900; color:#fff; letter-spacing:.5px;
  background:conic-gradient(from 180deg, var(--primary), var(--primary-2), var(--primary));
  box-shadow:inset 0 0 0 2px rgba(255,255,255,.45);
  user-select:none;
}
.user-name{ font-weight:800; color:var(--text) }

/* ===== PIN area ===== */
.pin-header{ display:flex; align-items:center; justify-content:center; gap:8px; font-weight:400; color:#1f2937; margin-bottom:6px; }
.pin-dots{ display:flex; justify-content:center; gap:12px; margin:8px 0 12px; }
.pin-dot{ width:16px; height:16px; border-radius:50%; background:#e5e7eb; box-shadow:inset 0 0 0 1px #d1d5db; transition:background .15s ease, transform .15s ease; }
.pin-dot.filled{ background:var(--primary); transform:scale(1.08) }
.input-shell{ display:none; }
.input-field{ display:none; }
.pin-grid{ display:grid; grid-template-columns:repeat(3,1fr); gap:10px; margin-top:2px; }
.key{ padding:16px; border:1px solid var(--border); border-radius:14px; background:#f3f4f6; font-size:18px; cursor:pointer; transition:background .1s ease, transform .05s ease; }
.key:hover{ background:#eceef1 }
.key:active{ transform:scale(.98) }
.btn{ cursor:pointer; background:var(--primary); color:#fff; border:1px solid var(--primary); border-radius:12px; padding:12px 14px; width:100%; transition:filter .15s ease, transform .05s ease, box-shadow .2s ease; box-shadow:0 6px 18px rgba(37,99,235,.25); }
.btn:hover{ filter:brightness(.98) }
.btn:active{ transform:translateY(1px) }

.hidden{display:none}
.muted{ color:var(--muted); font-size:12px; text-align:center }
.pad{ padding:6px 0 0 }

<?php if (!empty($error)): ?>
@keyframes shake{10%,90%{transform:translateX(-1px)}20%,80%{transform:translateX(2px)}30%,50%,70%{transform:translateX(-4px)}40%,60%{transform:translateX(4px)}}
.card{ animation:shake .4s ease-in-out }
<?php endif; ?>
</style>
</head>
<body>

  <div class="card">
    <div class="card-grid">

      <!-- LEFT: Big Moonshine “Smorll” -->
      <div class="left">
        <div class="brand-only">
          <div class="brand-monogram moonshine">Smorll</div>
        </div>
      </div>

      <!-- RIGHT: Form -->
      <div class="right">
        <form method="post" action="<?= htmlspecialchars(base_url('controllers/pos/auth_login.php')) ?>" id="loginForm">
          <?php if ($error): ?>
            <div class="error"><?= htmlspecialchars($error) ?></div>
          <?php endif; ?>

          <input type="hidden" id="username" name="username" required autocomplete="username" maxlength="100">

          <div class="box" id="userBox" aria-label="Select user">
            <div class="section-title">Select Username</div>
            <div class="user-grid-scroll">
              <div class="user-grid" id="userGrid">
                <button type="button" class="user-tile" data-username="posmanager" data-name="POS Manager">
                  <div class="avatar">PM</div>
                  <div class="user-name">POS Manager</div>
                </button>
                <button type="button" class="user-tile" data-username="cashier1" data-name="Cashier 1">
                  <div class="avatar">C1</div>
                  <div class="user-name">Cashier 1</div>
                </button>
                <button type="button" class="user-tile" data-username="cashier2" data-name="Cashier 2">
                  <div class="avatar">C2</div>
                  <div class="user-name">Cashier 2</div>
                </button>
                <button type="button" class="user-tile" data-username="supervisor" data-name="Supervisor">
                  <div class="avatar">SV</div>
                  <div class="user-name">Supervisor</div>
                </button>
                <button type="button" class="user-tile" data-username="chef" data-name="Chef">
                  <div class="avatar">CF</div>
                  <div class="user-name">Chef</div>
                </button>
                <button type="button" class="user-tile" data-username="host" data-name="Host">
                  <div class="avatar">HO</div>
                  <div class="user-name">Host</div>
                </button>
              </div>
            </div>
            <div class="pad muted">Tap a user to continue</div>
          </div>

          <!-- PIN INPUT -->
          <div id="pinBox" class="hidden" aria-label="Enter PIN">
            <div class="pin-header" id="pinTitle">Enter PIN · POS Manager</div>
            <div class="pin-dots">
              <div class="pin-dot" id="d1"></div>
              <div class="pin-dot" id="d2"></div>
              <div class="pin-dot" id="d3"></div>
              <div class="pin-dot" id="d4"></div>
            </div>
            <div class="input-shell">
              <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="4" required class="input-field">
            </div>
            <div class="pin-grid">
              <?php for($i=1;$i<=9;$i++): ?>
                <button type="button" class="key" onclick="addDigit('<?= $i ?>')"><?= $i ?></button>
              <?php endfor; ?>
              <button type="button" class="key" onclick="addDigit('0')">0</button>
              <button type="button" class="key" onclick="backspace()">⌫</button>
              <button type="submit" class="key">→</button>
            </div>
            <button class="btn" type="submit" style="margin-top:12px">Login</button>
            <div class="muted pad">
              <button type="button" id="changeUserBtn" style="border:none;background:transparent;cursor:pointer;color:var(--muted)">Change user</button>
            </div>
          </div>

          <?php if ($debug): ?>
            <div class="muted">DEBUG base_url: <?= htmlspecialchars(base_url('')) ?></div>
          <?php endif; ?>
        </form>
      </div>

    </div>
  </div>

<script>
function addDigit(d){ const el=document.getElementById('pin'); if(el && el.value.length<4){ el.value+=d; updateDots(); } }
function backspace(){ const el=document.getElementById('pin'); if(el){ el.value=el.value.slice(0,-1); updateDots(); } }
function updateDots(){ const el=document.getElementById('pin'); const l=(el?.value||'').length; for(let i=1;i<=4;i++){ const dot=document.getElementById('d'+i); if(dot){ dot.classList.toggle('filled', i<=l); } } }
(function(){
  const userBox=document.getElementById('userBox'); const pinBox=document.getElementById('pinBox');
  const userInp=document.getElementById('username'); const pinInp=document.getElementById('pin'); const pinTitle=document.getElementById('pinTitle');
  const changeBtn=document.getElementById('changeUserBtn');
  document.querySelectorAll('.user-tile').forEach(btn=>{
    btn.addEventListener('click',function(){
      document.querySelectorAll('.user-tile').forEach(b=>b.classList.remove('selected'));
      this.classList.add('selected');
      const username=this.getAttribute('data-username')||''; const label=this.getAttribute('data-name')||username;
      if(username){ userInp.value=username; pinTitle.textContent='Enter PIN · '+label; userBox.classList.add('hidden'); pinBox.classList.remove('hidden'); setTimeout(()=>{ pinInp && pinInp.focus(); },60); }
    });
  });
  if(changeBtn){ changeBtn.addEventListener('click',function(){ userInp.value=''; pinInp.value=''; updateDots(); pinBox.classList.add('hidden'); userBox.classList.remove('hidden'); }); }
  if(pinInp){ pinInp.addEventListener('input',updateDots); }
  updateDots();
})();
</script>
</body>
</html>