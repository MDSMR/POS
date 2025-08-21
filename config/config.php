<?php
return [
    // ===== Database =====
    'db' => [
        'host'    => 'localhost',          // If a later probe fails, try '127.0.0.1'
        'name'    => 'dbvtrnbzad193e',     // Your database name
        'user'    => 'u6yopmhusamog',      // NEW MySQL user
        'pass'    => '11|:66`_2jh_',       // NEW user's password
        'charset' => 'utf8mb4',
        'options' => [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ],
    ],

    // ===== App =====
    'app' => [
        'env'              => 'production',
        'base_url'         => '/',
        'session_name'     => 'smorll_session',
        'pos_session_name' => 'smorll_pos_session',
        'csrf_key'         => 'replace_with_a_long_random_string',
        'default_tenant_id'=> 1,
        'timezone'         => 'Asia/Kuwait',
        'password_cost'    => 12,
        'locale_default'   => 'en',
        'locales'          => ['en','ar'],
    ],

    // ===== Security / Headers =====
    'security' => [
        'allow_cors'           => false,
        'allowed_origins'      => [],
        'hsts'                 => true,
        'frame_deny'           => true,
        'xss_protection'       => '1; mode=block',
        'content_type_options' => 'nosniff',
        'referrer_policy'      => 'no-referrer',
    ],
];