<?php
// pos/logout.php — clears POS session
require_once __DIR__ . '/../config/db.php';
use_pos_session();
unset($_SESSION['pos_user'], $_SESSION['pos_permissions'], $_SESSION['pos_flash']);
header('Location: login.php');
exit;