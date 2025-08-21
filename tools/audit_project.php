<?php
/**
 * POS Restore Project Auditor
 * Location: /tools/audit_project.php
 * Purpose: Compare required files & DB tables to what exists; output a simple HTML report.
 * Notes:
 * - Tries to use your existing DB connection if /config/db.php defines $pdo.
 * - Else falls back to DSN from env vars.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

// ---- 0) Try to load existing PDO from your project config ----
$pdo = null;
$connectedVia = 'not connected';
try {
    $configDbPhp = __DIR__ . '/../config/db.php';
    if (file_exists($configDbPhp)) {
        require_once $configDbPhp; // if it sets $pdo, we will use it
        if (isset($pdo) && $pdo instanceof PDO) {
            $connectedVia = 'config/db.php';
        }
    }
} catch (Throwable $e) {
    // ignore, we’ll try env next
}

// ---- 1) Fallback: connect via ENV (recommended: configure in hosting panel) ----
if (!$pdo) {
    $dbHost = getenv('DB_HOST') ?: 'localhost';
    $dbName = getenv('DB_NAME') ?: 'dbvtrnbzad193e';
    $dbUser = getenv('DB_USER') ?: 'u6yopmhusamog';
    $dbPass = getenv('DB_PASS') ?: '';
    $charset = 'utf8mb4';
    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$charset}";
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $connectedVia = 'ENV DSN';
    } catch (Throwable $e) {
        $pdo = null;
        $connError = $e->getMessage();
    }
}

// ---- 2) Define expected artifacts ----
$projectRoot = realpath(__DIR__ . '/..');

$expected = [
    'Auth' => [
        'files' => [
            'views/auth/login.php',
            'controllers/AuthController.php',
            'models/User.php',
            'middleware/Auth.php',
        ],
        'tables' => ['users', 'user_sessions', 'password_resets'],
    ],
    'Admin Dashboard' => [
        'files' => [
            'views/admin/dashboard.php',
            'controllers/DashboardController.php',
        ],
        'tables' => [], // KPIs read from others
    ],
    'Products' => [
        'files' => [
            'views/products/index.php',
            'views/products/form.php',
            'controllers/ProductController.php',
            'models/Product.php',
        ],
        'tables' => ['products', 'categories', 'product_images'], // product_images optional
        'optional_tables' => ['product_images'],
    ],
    'Variations & Groups' => [
        'files' => [
            'views/variations/index.php',
            'views/variation_groups/index.php',
            'controllers/VariationController.php',
        ],
        'tables' => ['product_variations', 'product_variation_groups'],
    ],
    'Add-ons' => [
        'files' => [
            'views/addons/index.php',
            'controllers/AddonController.php',
        ],
        'tables' => ['addons', 'addon_groups', 'product_addon_groups'],
        'optional_tables' => ['product_addon_groups'],
    ],
    'Branch Availability' => [
        'files' => [
            'views/branches/availability.php',
            'controllers/BranchController.php',
        ],
        'tables' => ['branches', 'branch_product_availability'],
    ],
    'Orders' => [
        'files' => [
            'views/orders/index.php',
            'views/orders/show.php',
            'controllers/OrderController.php',
        ],
        'tables' => ['orders', 'order_items', 'order_discounts', 'order_loyalty_ledger', 'order_payments'],
    ],
    'Inventory' => [
        'files' => [
            'views/inventory/index.php',
            'controllers/InventoryController.php',
        ],
        'tables' => ['inventory', 'inventory_movements', 'suppliers'],
    ],
    'Settings & Charges' => [
        'files' => [
            'views/settings/index.php',
            'controllers/SettingsController.php',
        ],
        'tables' => ['settings', 'service_charges', 'roles_permissions'],
    ],
];

// ---- 3) Helper: file presence ----
function file_present($root, $relPath) {
    $path = $root . '/' . $relPath;
    return file_exists($path) ? $path : false;
}

// ---- 4) Get DB tables + basic FKs ----
$dbTables = [];
$fkInfo = [];
if ($pdo) {
    try {
        $dbName = $pdo->query("SELECT DATABASE() AS db")->fetch()['db'];
        $stmt = $pdo->prepare("
            SELECT table_name
            FROM information_schema.tables
            WHERE table_schema = :db
        ");
        $stmt->execute([':db' => $dbName]);
        foreach ($stmt as $row) {
            $dbTables[] = $row['table_name'];
        }

        $fkStmt = $pdo->prepare("
            SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL
            ORDER BY TABLE_NAME, CONSTRAINT_NAME
        ");
        $fkStmt->execute([':db' => $dbName]);
        $fkInfo = $fkStmt->fetchAll();
    } catch (Throwable $e) {
        $fkErr = $e->getMessage();
    }
}

// ---- 5) Compare ----
$report = [];
foreach ($expected as $area => $defs) {
    $files = $defs['files'] ?? [];
    $tables = $defs['tables'] ?? [];
    $optional = $defs['optional_tables'] ?? [];

    $fileResults = [];
    foreach ($files as $f) {
        $fileResults[$f] = file_present($projectRoot, $f) ? 'Present' : 'Missing';
    }

    $tableResults = [];
    foreach ($tables as $t) {
        $status = in_array($t, $dbTables, true) ? 'Present' : (in_array($t, $optional ?? [], true) ? 'Optional/Missing' : 'Missing');
        $tableResults[$t] = $status;
    }

    $report[$area] = [
        'files' => $fileResults,
        'tables' => $tableResults,
    ];
}

// ---- 6) HTML Output ----
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>POS Project Audit</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; margin: 24px; }
h1 { margin: 0 0 6px; }
small { color: #666; }
section { margin-top: 20px; }
table { border-collapse: collapse; width: 100%; margin-top: 10px; }
th, td { border: 1px solid #ddd; padding: 8px; vertical-align: top; }
th { background: #f7f7f7; text-align: left; }
.badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:12px; }
.present { background:#e8f5e9; color:#1b5e20; }
.missing { background:#ffebee; color:#b71c1c; }
.optional { background:#fff8e1; color:#8d6e63; }
.kv { margin: 2px 0; }
</style>
</head>
<body>
<h1>POS Project Audit</h1>
<small>
<?php
echo $pdo ? "DB status: <strong>Connected</strong> via {$connectedVia}" : "DB status: <strong>NOT connected</strong>" . (isset($connError) ? " — " . htmlspecialchars($connError) : "");
?>
</small>

<?php foreach ($report as $area => $res): ?>
<section>
    <h2><?php echo htmlspecialchars($area); ?></h2>

    <h3>Files</h3>
    <table>
        <tr><th>Path</th><th>Status</th></tr>
        <?php foreach ($res['files'] as $path => $status): ?>
            <tr>
                <td><?php echo htmlspecialchars($path); ?></td>
                <td>
                    <?php
                    $cls = $status === 'Present' ? 'present' : 'missing';
                    echo '<span class="badge ' . $cls . '">' . $status . '</span>';
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>

    <h3>Tables</h3>
    <table>
        <tr><th>Table</th><th>Status</th></tr>
        <?php foreach ($res['tables'] as $t => $status): ?>
            <tr>
                <td><?php echo htmlspecialchars($t); ?></td>
                <td>
                    <?php
                    $cls = $status === 'Present' ? 'present' : ($status === 'Optional/Missing' ? 'optional' : 'missing');
                    echo '<span class="badge ' . $cls . '">' . $status . '</span>';
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
</section>
<?php endforeach; ?>

<?php if ($pdo): ?>
<section>
    <h2>Detected Foreign Keys</h2>
    <table>
        <tr><th>Table</th><th>Constraint</th><th>Column</th><th>References</th></tr>
        <?php if (!empty($fkInfo)): ?>
            <?php foreach ($fkInfo as $fk): ?>
                <tr>
                    <td><?php echo htmlspecialchars($fk['TABLE_NAME']); ?></td>
                    <td><?php echo htmlspecialchars($fk['CONSTRAINT_NAME']); ?></td>
                    <td><?php echo htmlspecialchars($fk['COLUMN_NAME']); ?></td>
                    <td><?php echo htmlspecialchars($fk['REFERENCED_TABLE_NAME'] . '(' . $fk['REFERENCED_COLUMN_NAME'] . ')'); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="4">No foreign keys found or insufficient privileges.</td></tr>
        <?php endif; ?>
    </table>
</section>
<?php endif; ?>

</body>
</html>