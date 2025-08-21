<?php
require __DIR__ . '/../../config/db.php';
header('Content-Type: text/plain; charset=utf-8');
echo "PHP OK\n";
try {
  $pdo = db();
  $ok = $pdo->query('SELECT 1')->fetchColumn();
  echo "DB OK\n";
} catch (Throwable $e) {
  http_response_code(500);
  echo "DB ERROR\n";
}