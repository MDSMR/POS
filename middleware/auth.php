<?php
// public_html/middleware/auth.php — Minimal backend auth helpers with backward-compat aliases
declare(strict_types=1);

/**
 * Ensure a session exists (safe idempotent).
 * You already have use_backend_session() in config/db.php; this is a local fallback.
 */
if (session_status() === PHP_SESSION_NONE) {
  @session_start();
}

/**
 * Return current user array or null.
 */
function auth_user(): ?array {
  return $_SESSION['user'] ?? null;
}

/**
 * Require login: if there is no user, redirect to login.
 */
function auth_require_login(): void {
  if (!auth_user()) {
    header('Location: /views/auth/login.php');
    exit;
  }
}

/**
 * Optional auth init (does not redirect).
 */
function auth_optional(): void {
  // no-op beyond ensuring session above
}

/* ===== Backward-compat function names (to avoid fatals from legacy includes) ===== */
if (!function_exists('backend_require_login')) {
  function backend_require_login(): void { auth_require_login(); }
}
if (!function_exists('require_backend_login')) {
  function require_backend_login(): void { auth_require_login(); }
}
if (!function_exists('current_user')) {
  function current_user(): ?array { return auth_user(); }
}