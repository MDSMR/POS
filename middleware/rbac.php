<?php
// middleware/rbac.php — backend page-level RBAC
require_once __DIR__ . '/auth.php';

function require_admin(): void {
    auth_require_roles(['admin']);
}

function require_manager_or_admin(): void {
    auth_require_roles(['admin','manager']);
}

function require_content_managers(): void {
    auth_require_roles(['admin','manager','pos_manager']);
}