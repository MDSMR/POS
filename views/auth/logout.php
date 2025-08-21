<?php
// views/auth/logout.php — backend logout
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) {
    session_name('smorll_session');
    session_start();
}
$_SESSION = [];
session_destroy();
header('Location: /views/auth/login.php');
exit;