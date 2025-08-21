<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';
pos_session_start();

$error = $_GET['e'] ?? '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Smorll POS · POS Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="<?= htmlspecialchars(base_url('assets/css/admin.css')) ?>">
<style>
.login-wrap{max-width:360px;margin:10vh auto;background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
.login-head{padding:16px;border-bottom:1px solid var(--border);font-weight:700}
.login-body{padding:16px;display:grid;gap:10px}
.pin-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}
.pin-btn{padding:16px;border:1px solid var(--border);border-radius:10px;background:#f6f7fb;font-size:18px;cursor:pointer}
#pin{letter-spacing:4px;font-size:18px}
</style>
</head>
<body>
<div class="login-wrap">
  <div class="login-head">POS Login</div>
  <form class="login-body" method="post" action="<?= htmlspecialchars(base_url('controllers/pos/auth_login.php')) ?>">
    <?php if ($error): ?><div class="error" style="color:#b91c1c"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <label>Username
      <input name="username" required autocomplete="username" maxlength="100">
    </label>
    <label>PIN
      <input id="pin" name="pin" type="password" inputmode="numeric" pattern="[0-9]*" maxlength="6" required>
    </label>
    <div class="pin-grid">
      <?php for($i=1;$i<=9;$i++): ?>
        <button type="button" class="pin-btn" onclick="addDigit('<?= $i ?>')"><?= $i ?></button>
      <?php endfor; ?>
      <button type="button" class="pin-btn" onclick="addDigit('0')">0</button>
      <button type="button" class="pin-btn" onclick="backspace()">⌫</button>
      <button type="submit" class="pin-btn">→</button>
    </div>
    <button class="btn" type="submit">Login</button>
  </form>
</div>
<script>
function addDigit(d){ const el=document.getElementById('pin'); if(el.value.length<6){ el.value+=d; } }
function backspace(){ const el=document.getElementById('pin'); el.value=el.value.slice(0,-1); }
</script>
</body>
</html>