<?php
// public_html/views/admin/rewards/points/rules.php — Rewards · Points · Setup
// Goals: (1) beautiful, JSON-free UI, (2) show existing programs, (3) edit only non-calculation fields safely
declare(strict_types=1);

/* =========================
   Debug toggle
========================= */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors', '1');
  @ini_set('display_startup_errors', '1');
  error_reporting(E_ALL);
}

/* =========================
   Bootstrap: resolve /config/db.php robustly
========================= */
$bootstrap_warning = '';
$bootstrap_ok = false;
$bootstrap_tried = [];
$bootstrap_found = '';

$candA = __DIR__ . '/../../../../config/db.php';            // from /views/admin/rewards/points → /config/db.php
$bootstrap_tried[] = $candA;

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot !== '') {
  $candB = $docRoot . '/config/db.php';
  if (!in_array($candB, $bootstrap_tried, true)) $bootstrap_tried[] = $candB;
}

$cursor = __DIR__;
for ($i = 0; $i < 6; $i++) {
  $cursor = dirname($cursor);
  if ($cursor === '/' || $cursor === '.' || $cursor === '') break;
  $maybe = $cursor . '/config/db.php';
  if (!in_array($maybe, $bootstrap_tried, true)) $bootstrap_tried[] = $maybe;
}

foreach ($bootstrap_tried as $p) {
  if (is_file($p)) { $bootstrap_found = $p; break; }
}

if ($bootstrap_found === '') {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
  });
  try {
    require_once $bootstrap_found; // must define db(), use_backend_session()
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    } else {
      $bootstrap_ok = true;
    }
  } catch (Throwable $e) {
    $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
  } finally {
    if ($prevHandler) set_error_handler($prevHandler);
  }
}

if ($bootstrap_ok) {
  try { use_backend_session(); } catch (Throwable $e) {
    $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: ' . $e->getMessage());
  }
}

/* =========================
   Auth
========================= */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

/* =========================
   Data: fetch existing programs (points)
========================= */
$pdo = function_exists('db') ? db() : null;

$programs = [];
$live = null; $selected = null;
$selectId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($pdo instanceof PDO) {
  $sql = "SELECT id, name, status, program_type,
                 start_at, end_at, created_at, updated_at,
                 earn_mode, earn_rate, redeem_rate,
                 min_redeem_points, max_redeem_percent,
                 award_timing, expiry_policy, expiry_days, rounding
          FROM loyalty_programs
          WHERE tenant_id = :t AND program_type = 'points'
          ORDER BY
            (CASE WHEN start_at IS NULL THEN 0 ELSE 1 END),
            COALESCE(start_at, created_at) DESC, id DESC";
  try {
    $st = $pdo->prepare($sql);
    $st->execute([':t'=>$tenantId]);
    $programs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $bootstrap_warning = $bootstrap_warning ?: ('DB list error: ' . $e->getMessage());
  }
}

/* Identify live program (status active + within window) and selection */
$now = new DateTimeImmutable('now');
foreach ($programs as $row) {
  $isActive = ($row['status'] ?? '') === 'active';
  $startOk  = empty($row['start_at']) || (new DateTimeImmutable($row['start_at']) <= $now);
  $endOk    = empty($row['end_at'])   || (new DateTimeImmutable($row['end_at'])   >= $now);
  if ($isActive && $startOk && $endOk && $live === null) {
    $live = $row;
  }
  if ($selectId && (int)$row['id'] === $selectId) {
    $selected = $row;
  }
}
if (!$selected) { $selected = $live ?: ($programs[0] ?? null); }

/* Tabs helper */
function classify_program(array $r, DateTimeImmutable $now): string {
  $isActive = ($r['status'] ?? '') === 'active';
  $hasStart = !empty($r['start_at']);
  $hasEnd   = !empty($r['end_at']);
  $startOk  = !$hasStart || (new DateTimeImmutable($r['start_at']) <= $now);
  $endOk    = !$hasEnd   || (new DateTimeImmutable($r['end_at'])   >= $now);
  if ($isActive && $startOk && $endOk) return 'live';
  if ($hasStart && (new DateTimeImmutable($r['start_at']) > $now)) return 'scheduled';
  return 'past';
}

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES); }

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Rewards · Points Setup · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--panel:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb;--pill:#eef2ff;--warn:#f59e0b}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial}
.container{max-width:1150px;margin:18px auto;padding:0 16px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;box-shadow:0 6px 24px rgba(0,0,0,.06);padding:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 6px}
.sub{color:var(--muted);font-size:13px;margin:0}
.badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;border:1px solid var(--border)}
.b-live{color:#065f46;background:#d1fae5;border-color:#a7f3d0}
.b-scheduled{color:#1d4ed8;background:#dbeafe;border-color:#bfdbfe}
.b-past{color:#374151;background:#f3f4f6;border-color:#e5e7eb}
.b-inactive{color:#92400e;background:#fef3c7;border-color:#fde68a}

.grid{display:grid;gap:14px}
.grid-2{grid-template-columns:1.7fr 1fr}
@media (max-width:980px){.grid-2{grid-template-columns:1fr}}

.controls{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
input,select,button,textarea{padding:8px 10px;border:1px solid var(--border);border-radius:10px;background:#fff;font:inherit}
button.btn, a.btn{display:inline-block;padding:10px 14px;border:1px solid var(--border);border-radius:10px;background:#fff;text-decoration:none;color:var(--text);cursor:pointer}
.btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
.btn.ghost{background:#fff}
.btn.warn{background:#fff7ed;border-color:#ffedd5}
.btn:hover{background:#f3f4f6}.btn.primary:hover{filter:brightness(.98)}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px}

.tabs{display:flex;gap:6px;margin:8px 0}
.tabs a{padding:6px 10px;border:1px solid var(--border);border-radius:999px;text-decoration:none;color:#374151}
.tabs a.active{background:var(--pill);font-weight:700}

.table-wrap{overflow:auto}
.table{width:100%;border-collapse:separate;border-spacing:0}
.table th,.table td{padding:10px 12px;border-bottom:1px solid var(--border);background:#fff;white-space:nowrap}
.table thead th{position:sticky;top:0;background:#f9fafb;font-weight:600}

.kv{display:grid;grid-template-columns:140px 1fr;gap:8px;align-items:center}
.kv label{color:#374151;font-weight:600}
.kv .help{font-size:12px;color:var(--muted)}
hr.sep{border:0;border-top:1px solid var(--border);margin:12px 0}
</style>
</head>
<body>

<?php
/* Safe nav include */
$active = 'rewards_points';
$nav_included = false;
$nav1 = __DIR__ . '/../../partials/admin_nav.php';        // /views/admin/partials/admin_nav.php
$nav2 = dirname(__DIR__, 3) . '/partials/admin_nav.php';  // /views/partials/admin_nav.php
if (is_file($nav1)) { $nav_included = (bool) @include $nav1; }
elseif (is_file($nav2)) { $nav_included = (bool) @include $nav2; }
?>

<div class="container">
  <?php if ($bootstrap_warning): ?>
    <div class="notice" style="margin-bottom:12px;"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>

  <!-- Page header -->
  <div class="card" style="margin-bottom:14px;">
    <div class="controls" style="justify-content:space-between;align-items:flex-start;">
      <div>
        <div class="h1">Points — Setup</div>
        <p class="sub">Manage program versions. Edit only **non-calculation** fields here (safe). To change earning or redemption math, create a new version.</p>
      </div>
      <div class="controls">
        <a class="btn" href="/views/admin/rewards/index.php">Rewards Home</a>
        <a class="btn primary" href="/views/admin/rewards/points/version_new.php">Create New Version</a>
      </div>
    </div>
  </div>

  <div class="grid grid-2">
    <!-- Left: Programs list -->
    <section class="card">
      <div class="controls" style="justify-content:space-between;">
        <div class="h1" style="margin:0;">Existing Points Programs</div>
        <div class="tabs" role="tablist" aria-label="Program filters">
          <?php
            $tab = $_GET['tab'] ?? 'live';
            $tabs = ['live'=>'Live','scheduled'=>'Scheduled','past'=>'Past'];
            foreach ($tabs as $k=>$label) {
              $is = $tab===$k ? 'active' : '';
              $url = '?tab='.$k;
              echo '<a class="'.$is.'" href="'.$url.'">'.h($label).'</a>';
            }
          ?>
        </div>
      </div>

      <div class="table-wrap" style="margin-top:8px;">
        <table class="table" aria-label="Programs">
          <thead>
            <tr>
              <th>#</th>
              <th>Name</th>
              <th>Status</th>
              <th>Window</th>
              <th>Earning</th>
              <th>Redemption</th>
              <th>Timing</th>
              <th>Updated</th>
              <th style="text-align:right;">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php
            if (!$programs) {
              echo '<tr><td colspan="9" class="sub">No programs yet. Click “Create New Version”.</td></tr>';
            } else {
              foreach ($programs as $r) {
                $cls = classify_program($r, $now);
                if ($tab !== $cls) continue;

                $badge = $cls==='live' ? 'b-live' : ($cls==='scheduled' ? 'b-scheduled' : 'b-past');
                $statusBadge = ($r['status']==='active') ? '<span class="badge b-live">Active</span>' : '<span class="badge b-inactive">Inactive</span>';

                $win = (empty($r['start_at'])?'—':h($r['start_at'])) . ' → ' . (empty($r['end_at'])?'—':h($r['end_at']));
                $earn = ($r['earn_mode'] ?: 'per_currency') . ' · rate ' . h($r['earn_rate']);
                $redeem = 'rate ' . h($r['redeem_rate']);
                if ($r['min_redeem_points'] !== null) $redeem .= ' · min ' . h($r['min_redeem_points']);
                if ($r['max_redeem_percent'] !== null) $redeem .= ' · cap ' . h($r['max_redeem_percent']).'%';
                $timing = h($r['award_timing'] ?: 'on_payment');

                echo '<tr>';
                echo '<td>'.h($r['id']).'</td>';
                echo '<td><span class="badge '.$badge.'" style="margin-right:6px;">'.ucfirst($cls).'</span>'.h($r['name']).'</td>';
                echo '<td>'.$statusBadge.'</td>';
                echo '<td>'.$win.'</td>';
                echo '<td>'.$earn.'</td>';
                echo '<td>'.$redeem.'</td>';
                echo '<td>'.$timing.'</td>';
                echo '<td>'.h($r['updated_at'] ?? '').'</td>';
                echo '<td style="text-align:right;">
                        <a class="btn" href="?tab='.h($tab).'&id='.h($r['id']).'">View</a>
                        <a class="btn" href="/views/admin/rewards/points/version_view.php?id='.h($r['id']).'">Details</a>
                      </td>';
                echo '</tr>';
              }
            }
            ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Right: Edit details (non-calculation) -->
    <aside class="card">
      <div class="h1">Edit Details (safe)</div>
      <?php if (!$selected): ?>
        <p class="sub">Select a program on the left to edit its details.</p>
      <?php else: ?>
        <?php
          $cls = classify_program($selected, $now);
          $safeTip = $cls==='live'
            ? 'You can safely edit Name and Status for the live program. To change earning or redemption, create a new version.'
            : 'You can safely edit Name and Status. For any math changes, use “Create New Version”.';
        ?>
        <p class="sub" style="margin-bottom:10px;"><?= h($safeTip) ?></p>

        <form method="post" action="/controllers/admin/rewards/programs/update_meta.php" class="kv" style="row-gap:10px;">
          <input type="hidden" name="id" value="<?= h($selected['id']) ?>">
          <input type="hidden" name="program_type" value="points">
          <label>Program ID</label><div><?= h($selected['id']) ?></div>

          <label>Name</label>
          <div><input name="name" value="<?= h($selected['name']) ?>"></div>

          <label>Status</label>
          <div>
            <select name="status">
              <option value="active"   <?= ($selected['status']==='active')?'selected':''; ?>>Active</option>
              <option value="inactive" <?= ($selected['status']!=='active')?'selected':''; ?>>Inactive</option>
            </select>
          </div>

          <label>Award timing</label>
          <div>
            <select name="award_timing" disabled title="Change via New Version">
              <?php $aw = $selected['award_timing'] ?: 'on_payment'; ?>
              <option value="on_payment" <?= $aw==='on_payment'?'selected':''; ?>>On payment</option>
              <option value="on_close"   <?= $aw==='on_close'?'selected':''; ?>>On close</option>
            </select>
            <div class="help">Timing affects behavior; change it when creating a new version.</div>
          </div>

          <hr class="sep">

          <div style="grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn primary" type="submit">Save</button>
            <a class="btn" href="/views/admin/rewards/points/version_new.php">Create New Version</a>
            <a class="btn ghost" href="?tab=<?= h($_GET['tab'] ?? 'live') ?>&id=<?= h($selected['id']) ?>">Cancel</a>
          </div>
        </form>

        <hr class="sep">

        <div class="kv">
          <label>Window</label><div><?= (empty($selected['start_at'])?'—':h($selected['start_at'])) ?> → <?= (empty($selected['end_at'])?'—':h($selected['end_at'])) ?></div>
          <label>Earning</label><div><?= h(($selected['earn_mode'] ?: 'per_currency').' · rate '.$selected['earn_rate']) ?></div>
          <label>Redemption</label>
          <div>
            <?php
              $txt = 'rate ' . h($selected['redeem_rate']);
              if ($selected['min_redeem_points'] !== null) $txt .= ' · min ' . h($selected['min_redeem_points']);
              if ($selected['max_redeem_percent'] !== null) $txt .= ' · cap ' . h($selected['max_redeem_percent']).'%';
              echo $txt;
            ?>
          </div>
          <label>Expiry</label>
          <div><?= h(($selected['expiry_policy'] ?: 'bucket_days')) ?><?= $selected['expiry_days']!==null?' · '.h($selected['expiry_days']).' days':'' ?></div>
          <label>Rounding</label><div><?= h($selected['rounding'] ?: 'floor') ?></div>
          <label>Updated</label><div><?= h($selected['updated_at'] ?? '') ?></div>
        </div>
      <?php endif; ?>
    </aside>
  </div>

  <?php if ($DEBUG): ?>
    <div class="card" style="margin-top:14px;">
      <div class="h1">Debug</div>
      <div class="sub">db.php tried:</div>
      <pre style="white-space:pre-wrap;background:#f9fafb;border:1px solid var(--border);padding:10px;border-radius:8px"><?php
        foreach ($bootstrap_tried as $p) echo " - $p\n";
      ?></pre>
      <div class="sub">Resolved: <?= h($bootstrap_found ?: 'NOT FOUND') ?></div>
    </div>
  <?php endif; ?>
</div>
</body>
</html>