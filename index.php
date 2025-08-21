<?php
// index.php — smart redirector
require __DIR__ . '/config/db.php';

$area = $_GET['area'] ?? '';
if ($area === 'pos') {
    redirect('pos'); // /pos handles POS login routing
} elseif ($area === 'admin') {
    redirect('views/auth/login.php');
} else {
    // default: go to admin login
    redirect('views/auth/login.php');
}