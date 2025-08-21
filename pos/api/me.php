<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';

pos_require_user();

json_out([
    'ok' => true,
    'user' => pos_user(),
    'permissions' => pos_permissions(),
]);