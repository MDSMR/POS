<?php
// views/auth/login.php — Admin login (safe: no includes on GET)
declare(strict_types=1);

// --- Page-local settings ---
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';

// Minimal session for CSRF on this page only
if (session_status() === PHP_SESSION_NONE) {
    session_name('smorll_session');
    session_start();
}
function page_csrf_token(): string {
    if (empty($_SESSION['admin_login_csrf'])) {
        $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['admin_login_csrf'];
}
function page_csrf_verify(string $t): bool {
    return isset($_SESSION['admin_login_csrf']) && hash_equals($_SESSION['admin_login_csrf'], $t);
}

// If already logged in to backend, go to dashboard
if (!empty($_SESSION['user'])) {
    header('Location: /views/admin/dashboard.php');
    exit;
}

$errors = [];
$bootstrap_warning = '';

// --- Safe bootstrap (no 500s) ---
$bootstrap_ok = false;
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) {
    $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
    // Convert warnings/notices during require to exceptions so we can show them nicely
    $prevHandler = set_error_handler(function($severity, $message, $file, $line) {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        require $bootstrap_path; // should define db() and use_backend_session()
        if (!function_exists('db') || !function_exists('use_backend_session')) {
            $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
        } else {
            $bootstrap_ok = true;
        }
    } catch (Throwable $e) {
        $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
    } finally {
        if ($prevHandler) { set_error_handler($prevHandler); }
    }
}

// Handle POST only if no CSRF errors
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $csrf     = (string)($_POST['csrf'] ?? '');

    if (!page_csrf_verify($csrf)) {
        $errors[] = 'Invalid request. Please refresh the page and try again.';
    }
    if ($email === '' || $password === '') {
        $errors[] = 'Email and password are required.';
    }

    if (!$errors) {
        if (!$bootstrap_ok) {
            // Don’t fatal—show a friendly message
            $errors[] = 'Server configuration issue. Please contact the administrator.';
        } else {
            try {
                use_backend_session(); // from config/db.php

                $pdo = db(); // from config/db.php
                if (!$pdo) {
                    throw new RuntimeException('Database connection not available.');
                }

                $stmt = $pdo->prepare("
                    SELECT id, tenant_id, name, username, email, password_hash, role_key, disabled_at
                    FROM users
                    WHERE email = :email
                    LIMIT 1
                ");
                $stmt->execute([':email' => $email]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || $user['disabled_at'] !== null) {
                    $errors[] = 'Invalid credentials.';
                } elseif (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
                    $errors[] = 'Invalid credentials.';
                } else {
                    // Success: store backend session
                    $_SESSION['user'] = [
                        'id'        => (int)$user['id'],
                        'tenant_id' => (int)$user['tenant_id'],
                        'name'      => $user['name'],
                        'username'  => $user['username'],
                        'email'     => $user['email'],
                        'role_key'  => $user['role_key'],
                    ];
                    unset($_SESSION['admin_login_csrf']);
                    header('Location: /views/admin/dashboard.php');
                    exit;
                }
            } catch (Throwable $e) {
                $errors[] = 'Login error. Please try again.';
                if ($DEBUG) {
                    $errors[] = 'DEBUG: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Login · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
/* Moonshine font for the wordmark */
@font-face{
  font-family:"Moonshine";
  src: url("/assets/fonts/Moonshine.woff2") format("woff2"),
       url("/assets/fonts/Moonshine.woff") format("woff");
  font-display: swap;
}
.moonshine{ font-family:"Moonshine", cursive; font-weight:400; }

:root{--bg:#f7f8fa;--card:#ffffff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--danger:#dc2626;--border:#e5e7eb;--accent-red:#e11d48}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:15px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
.container{max-width:380px;margin:6vh auto;padding:20px}
.card{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:22px}
h1{font-size:20px;margin:0 0 12px;text-align:center}
label{display:block;margin:10px 0 6px;color:var(--muted)}
input[type=email],input[type=password]{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:10px}
.btn{width:100%;margin-top:14px;padding:12px 14px;background:var(--primary);color:#fff;border:0;border-radius:10px;cursor:pointer}
.btn:hover{filter:brightness(.98)}
.error{background:#fee2e2;border:1px solid #fecaca;color:#7f1d1d;padding:10px;border-radius:10px;margin:10px 0}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}

/* Wordmark instead of logo image */
.logo-text{display:flex;justify-content:center;align-items:center;margin-bottom:14px;font-size:42px;color:var(--accent-red);line-height:1}
.small{color:var(--muted);font-size:12px;text-align:center}
</style>
</head>
<body>
  <div class="container">
    <div class="card">
      <div class="logo-text moonshine">Smorll</div>
      <h1>Sign in</h1>

      <?php if ($bootstrap_warning): ?>
        <div class="notice">
          <?= htmlspecialchars($bootstrap_warning, ENT_QUOTES, 'UTF-8') ?>
          <?php if ($DEBUG): ?>
            <div class="small">Tip: ensure <code>config/db.php</code> defines <code>db()</code> and <code>use_backend_session()</code>.</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($errors): ?>
        <div class="error">
          <?php foreach ($errors as $e): ?>
            <div>• <?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <form method="post" action="">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars(page_csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="username" required value="admin@example.com">

        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required value="ChangeMe123!">

        <button class="btn" type="submit">Login</button>
      </form>

      <div class="small" style="margin-top:10px">
        <a href="/pos/login.php" style="color:var(--primary);text-decoration:none">Go to POS</a>
        <?php if ($DEBUG): ?>
          <div style="margin-top:6px">DEBUG is ON for this page.</div>
        <?php else: ?>
          <div style="margin-top:6px"><a href="?debug=1" style="color:var(--primary);text-decoration:none">Enable debug</a></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>