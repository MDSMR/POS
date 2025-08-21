<?php
// controllers/admin/modifiers_save.php — Save Modifier Group + inline Values (create/update), tenant-scoped
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];

if (empty($_SESSION['csrf_mod']) || ($_POST['csrf'] ?? '') !== $_SESSION['csrf_mod']) {
  $_SESSION['flash'] = 'Invalid request.';
  header('Location: /views/admin/modifiers.php'); exit;
}

/* Helpers */
function col_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $q->execute([':t'=>$t, ':c'=>$c]); return (bool)$q->fetchColumn();
}
function norm_dec($s){ $s=trim((string)$s); if($s==='') return '0.00'; return number_format((float)$s, 2, '.', ''); }

/* Group input */
$id   = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$sort = (int)($_POST['sort_order'] ?? 1);
$act  = (int)($_POST['is_active'] ?? 1);
$vis  = (int)($_POST['pos_visible'] ?? 1);

if ($name === '') {
  $_SESSION['flash'] = 'Group Name is required.';
  header('Location: ' . ($id>0 ? '/views/admin/modifier_edit.php?id='.$id : '/views/admin/modifier_new.php')); exit;
}

/* Values arrays */
$val_ids   = (array)($_POST['value_id'] ?? []);
$val_en    = (array)($_POST['value_en'] ?? []);
$val_ar    = (array)($_POST['value_ar'] ?? []);
$val_delta = (array)($_POST['price_delta'] ?? []);
$val_sort  = (array)($_POST['value_sort'] ?? []);
$val_act   = (array)($_POST['value_active'] ?? []);
$val_vis   = (array)($_POST['value_visible'] ?? []);

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Ensure pos_visible columns exist
  if (!col_exists($pdo,'variation_groups','pos_visible')) {
    try { $pdo->exec("ALTER TABLE variation_groups ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active"); } catch(Throwable $e){}
  }
  if (!col_exists($pdo,'variation_values','pos_visible')) {
    try { $pdo->exec("ALTER TABLE variation_values ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active"); } catch(Throwable $e){}
  }
  // Ensure tenant_id exists on variation_groups (your schema already has it; this is just a guard)
  if (!col_exists($pdo,'variation_groups','tenant_id')) {
    throw new RuntimeException('variation_groups.tenant_id is missing.');
  }

  $pdo->beginTransaction();

  // For updates, verify ownership (group belongs to this tenant)
  if ($id > 0) {
    $chk = $pdo->prepare("SELECT id FROM variation_groups WHERE id=:id AND tenant_id=:t LIMIT 1");
    $chk->execute([':id'=>$id, ':t'=>$tenantId]);
    if (!$chk->fetchColumn()) {
      throw new RuntimeException('Group not found for this tenant.');
    }
    $st = $pdo->prepare("
      UPDATE variation_groups
         SET name=:n, sort_order=:s, is_active=:a, pos_visible=:v, updated_at=NOW()
       WHERE id=:id AND tenant_id=:t
       LIMIT 1
    ");
    $st->execute([':n'=>$name, ':s'=>$sort, ':a'=>$act, ':v'=>$vis, ':id'=>$id, ':t'=>$tenantId]);
    $group_id = $id;
  } else {
    // Insert with tenant_id
    $st = $pdo->prepare("
      INSERT INTO variation_groups (tenant_id, name, sort_order, is_active, pos_visible, created_at, updated_at)
      VALUES (:t,:n,:s,:a,:v,NOW(),NOW())
    ");
    $st->execute([':t'=>$tenantId, ':n'=>$name, ':s'=>$sort, ':a'=>$act, ':v'=>$vis]);
    $group_id = (int)$pdo->lastInsertId();
  }

  // Load existing values for diff (through group_id already tenant-scoped)
  $existing = [];
  $st = $pdo->prepare("SELECT id FROM variation_values WHERE group_id=:g");
  $st->execute([':g'=>$group_id]);
  foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $vid) { $existing[(int)$vid] = true; }

  // Upsert values
  $kept = [];
  $rows = max(count($val_en), count($val_ids));
  for ($i=0; $i<$rows; $i++) {
    $vid = isset($val_ids[$i]) ? (int)$val_ids[$i] : 0;
    $en  = isset($val_en[$i])  ? trim((string)$val_en[$i]) : '';
    $ar  = isset($val_ar[$i])  ? trim((string)$val_ar[$i]) : '';
    $dlt = isset($val_delta[$i]) ? norm_dec($val_delta[$i]) : '0.00';
    $srt = isset($val_sort[$i]) ? (int)$val_sort[$i] : 1;
    $vac = isset($val_act[$i]) ? (int)$val_act[$i] : 1;
    $vvi = isset($val_vis[$i]) ? (int)$val_vis[$i] : 1;

    if ($en === '') { continue; }

    if ($vid > 0 && isset($existing[$vid])) {
      // update value
      $st = $pdo->prepare("
        UPDATE variation_values
           SET value_en=:en, value_ar=:ar, price_delta=:d, sort_order=:s, is_active=:a, pos_visible=:v, updated_at=NOW()
         WHERE id=:id AND group_id=:g
         LIMIT 1
      ");
      $st->execute([':en'=>$en, ':ar'=>$ar, ':d'=>$dlt, ':s'=>$srt, ':a'=>$vac, ':v'=>$vvi, ':id'=>$vid, ':g'=>$group_id]);
      $kept[$vid] = true;
    } else {
      // insert value
      $st = $pdo->prepare("
        INSERT INTO variation_values
          (group_id, value_en, value_ar, price_delta, sort_order, is_active, pos_visible, created_at, updated_at)
        VALUES
          (:g, :en, :ar, :d, :s, :a, :v, NOW(), NOW())
      ");
      $st->execute([':g'=>$group_id, ':en'=>$en, ':ar'=>$ar, ':d'=>$dlt, ':s'=>$srt, ':a'=>$vac, ':v'=>$vvi]);
      $newId = (int)$pdo->lastInsertId();
      $kept[$newId] = true;
    }
  }

  // Delete removed values
  if (!empty($existing)) {
    $toDelete = array_diff(array_keys($existing), array_keys($kept));
    if (!empty($toDelete)) {
      $in = implode(',', array_fill(0, count($toDelete), '?'));
      $st = $pdo->prepare("DELETE FROM variation_values WHERE group_id = ? AND id IN ($in)");
      $bind = array_merge([$group_id], array_map('intval', $toDelete));
      $st->execute($bind);
    }
  }

  $pdo->commit();
  $_SESSION['flash'] = 'Modifier group saved.';
  header('Location: /views/admin/modifiers.php'); exit;

} catch (Throwable $e) {
  if (!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash'] = 'Save error. ' . $e->getMessage();
  header('Location: ' . ($id>0 ? '/views/admin/modifier_edit.php?id='.$id : '/views/admin/modifier_new.php')); exit;
}