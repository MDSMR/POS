<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';
pos_session_start();
unset($_SESSION['pos']);
header('Location: '.base_url('views/pos/login.php'));