<?php
// public_html/views/admin/_header.php — Shared bootstrap for admin pages
declare(strict_types=1);

// Hard errors visible when needed
if (isset($_GET['debug']) && $_GET['debug'] === '1') {
  ini_set('display_errors','1');
  ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
}

/* ===== Bootstrap config/db.php ===== */
$__db_path = __DIR__ . '/../../config/db.php';
if (!is_file($__db_path)) {
  http_response_code(500);
  exit('Configuration file not found: /config/db.php');
}
require_once $__db_path;            // defines db(), use_backend_session()
if (function_exists('use_backend_session')) { use_backend_session(); }
else { if (session_status() === PHP_SESSION_NONE) { @session_start(); } }

/* ===== Auth middleware ===== */
$__auth_path = __DIR__ . '/../../middleware/auth.php';
if (is_file($__auth_path)) { require_once $__auth_path; }

/* If the legacy/compat or new helpers are available, enforce login */
if (function_exists('auth_require_login')) {
  auth_require_login();
} elseif (function_exists('backend_require_login')) {
  backend_require_login();
} else {
  // Fallback: simple check
  if (empty($_SESSION['user'])) {
    header('Location: /views/auth/login.php');
    exit;
  }
}

/* ===== Common helpers (safe if already defined elsewhere) ===== */
if (!function_exists('tenant_id')) {
  function tenant_id(): int {
    $u = $_SESSION['user'] ?? null;
    return (int)($u['tenant_id'] ?? 0);
  }
}

if (!function_exists('base_url')) {
  function base_url(string $path = ''): string {
    $path = '/' . ltrim($path, '/');
    return $path;
  }
}

/* Expose $user for views */
$user = $_SESSION['user'] ?? null;