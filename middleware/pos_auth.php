<?php
// middleware/pos_auth.php — POS session helpers (root-based URLs)

function pos_session_start(){
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
  if (!isset($_SESSION['pos'])) $_SESSION['pos'] = [];
}

function pos_user(){
  pos_session_start();
  return $_SESSION['pos']['user'] ?? null;
}

if (!function_exists('base_url')) {
  function base_url(string $path = ''): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return rtrim($scheme.'://'.$host, '/').'/'.ltrim($path, '/');
  }
}

function pos_login_url(): string {
  // We standardize POS login at /pos/login.php
  return base_url('pos/login.php');
}

function pos_auth_require_login(){
  if (!pos_user()) {
    header('Location: '.pos_login_url());
    exit;
  }
}