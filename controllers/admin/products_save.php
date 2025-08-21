<?php
// controllers/admin/products_save.php — Create/Update + relations (redirect to products list)
declare(strict_types=1);

/* Bootstrap + session */
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) { http_response_code(500); exit('Configuration file not found: /config/db.php'); }
require_once $bootstrap_path;
if (!function_exists('db') || !function_exists('use_backend_session')) { http_response_code(500); exit('Required functions missing in config/db.php (db(), use_backend_session()).'); }
use_backend_session();

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* CSRF */
if (empty($_SESSION['csrf_products']) || ($_POST['csrf'] ?? '') !== $_SESSION['csrf_products']) {
  $_SESSION['flash'] = 'Invalid request. Please try again.';
  header('Location: /views/admin/products.php'); exit;
}

/* Helpers */
function hstr($s){ return trim((string)$s); }
function num($s){ $s = trim((string)$s); if ($s==='') return null; return (float)preg_replace('/[^\d.\-]/','',$s); }
function table_exists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $stmt->execute([':t'=>$table]); return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) { return false; }
}

/* Input */
$id             = (int)($_POST['id'] ?? 0);
$name_en        = hstr($_POST['name_en'] ?? '');
$name_ar        = hstr($_POST['name_ar'] ?? '');
$desc_en        = (string)($_POST['description'] ?? '');
$desc_ar        = (string)($_POST['description_ar'] ?? '');
$price          = num($_POST['price'] ?? '');
$standard_cost  = num($_POST['standard_cost'] ?? '');
$is_open_price  = isset($_POST['is_open_price']) ? 1 : 0;
$weight_kg      = num($_POST['weight_kg'] ?? '');
$calories       = $_POST['calories'] !== '' ? (int)$_POST['calories'] : null;
$prep_time_min  = $_POST['prep_time_min'] !== '' ? (int)$_POST['prep_time_min'] : null;
$is_active      = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$pos_visible    = isset($_POST['pos_visible']) ? (int)$_POST['pos_visible'] : 1;

$categories     = isset($_POST['categories']) && is_array($_POST['categories']) ? array_map('intval', $_POST['categories']) : [];
$branches       = isset($_POST['branches']) && is_array($_POST['branches']) ? array_map('intval', $_POST['branches']) : [];
$mod_groups_in  = isset($_POST['mod_groups']) && is_array($_POST['mod_groups']) ? array_map('intval', $_POST['mod_groups']) : [];
$mod_options_in = isset($_POST['mod_options']) && is_array($_POST['mod_options']) ? array_map(fn($v)=>($v===''?null:(int)$v), $_POST['mod_options']) : [];

if ($is_open_price) {
  if ($price === null)         $price = 0.00;
  if ($standard_cost === null) $standard_cost = 0.00;
}
if ($price === null)         $price = 0.00;
if ($standard_cost === null) $standard_cost = 0.00;

/* Image Upload (optional) */
$image_path = null;
if (!empty($_FILES['image']['name']) && is_uploaded_file($_FILES['image']['tmp_name'])) {
  $f = $_FILES['image'];
  if ($f['error'] === UPLOAD_ERR_OK) {
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg','jpeg','png','webp'])) {
      $dir = __DIR__ . '/../../uploads/products';
      if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
      $basename = 'p_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $full = $dir . '/' . $basename;
      if (@move_uploaded_file($f['tmp_name'], $full)) {
        $image_path = '/uploads/products/' . $basename;
      }
    }
  }
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Optional tables detection
  $has_pc   = table_exists($pdo, 'product_categories');
  $has_pb   = table_exists($pdo, 'product_branches');
  $has_pm   = table_exists($pdo, 'product_modifiers');

  $pdo->beginTransaction();

  if ($id > 0) {
    $sql = "
      UPDATE products SET
        name_en = :name_en,
        name_ar = :name_ar,
        description = :desc_en,
        description_ar = :desc_ar,
        price = :price,
        standard_cost = :standard_cost,
        is_open_price = :is_open_price,
        weight_kg = :weight_kg,
        calories = :calories,
        prep_time_min = :prep_time_min,
        is_active = :is_active,
        pos_visible = :pos_visible,
        updated_at = NOW()
        " . ($image_path ? ", image_path = :image_path" : "") . "
      WHERE id = :id
      LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
  } else {
    $sql = "
      INSERT INTO products
        (name_en, name_ar, description, description_ar, price, standard_cost, is_open_price,
         weight_kg, calories, prep_time_min, is_active, pos_visible, created_at, updated_at, image_path)
      VALUES
        (:name_en, :name_ar, :desc_en, :desc_ar, :price, :standard_cost, :is_open_price,
         :weight_kg, :calories, :prep_time_min, :is_active, :pos_visible, NOW(), NOW(), :image_path)
    ";
    $stmt = $pdo->prepare($sql);
  }

  $stmt->bindValue(':name_en', $name_en);
  $stmt->bindValue(':name_ar', $name_ar);
  $stmt->bindValue(':desc_en', $desc_en);
  $stmt->bindValue(':desc_ar', $desc_ar);
  $stmt->bindValue(':price', $price);
  $stmt->bindValue(':standard_cost', $standard_cost);
  $stmt->bindValue(':is_open_price', $is_open_price, PDO::PARAM_INT);
  $stmt->bindValue(':weight_kg', $weight_kg !== null ? $weight_kg : null);
  $stmt->bindValue(':calories', $calories !== null ? $calories : null, $calories !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
  $stmt->bindValue(':prep_time_min', $prep_time_min !== null ? $prep_time_min : null, $prep_time_min !== null ? PDO::PARAM_INT : PDO::PARAM_NULL);
  $stmt->bindValue(':is_active', $is_active, PDO::PARAM_INT);
  $stmt->bindValue(':pos_visible', $pos_visible, PDO::PARAM_INT);
  if ($image_path || $id <= 0) $stmt->bindValue(':image_path', $image_path);
  $stmt->execute();

  if ($id <= 0) { $id = (int)$pdo->lastInsertId(); }

  /* Relations: Categories */
  if ($has_pc) {
    $pdo->prepare("DELETE FROM product_categories WHERE product_id = :p")->execute([':p'=>$id]);
    if (!empty($categories)) {
      $ins = $pdo->prepare("INSERT INTO product_categories (product_id, category_id) VALUES (:p, :c)");
      foreach (array_unique($categories) as $cid) {
        if ($cid > 0) { $ins->execute([':p'=>$id, ':c'=>$cid]); }
      }
    }
  }

  /* Relations: Branches */
  if ($has_pb) {
    $pdo->prepare("DELETE FROM product_branches WHERE product_id = :p")->execute([':p'=>$id]);
    if (!empty($branches)) {
      $ins = $pdo->prepare("INSERT INTO product_branches (product_id, branch_id) VALUES (:p, :b)");
      foreach (array_unique($branches) as $bid) {
        if ($bid > 0) { $ins->execute([':p'=>$id, ':b'=>$bid]); }
      }
    }
  }

  /* Relations: Modifiers  (group = single, values = multiple)
     - Front-end sends parallel arrays: mod_groups[], mod_options[] (one entry per selected value).
     - For a group with NO selected values, we send exactly one pair (gid, '').
     - We de-duplicate values per group, validate value belongs to the group, and insert.
  */
  if ($has_pm) {
    $pdo->prepare("DELETE FROM product_modifiers WHERE product_id = :p")->execute([':p'=>$id]);

    // Build pairs safely
    $pairs = [];
    $count = min(count($mod_groups_in), count($mod_options_in));
    for ($i=0; $i<$count; $i++) {
      $g = (int)$mod_groups_in[$i];
      $o = $mod_options_in[$i]; // null if ''
      if ($g > 0) { $pairs[] = [$g, ($o === null ? null : (int)$o)]; }
    }

    if (!empty($pairs)) {
      // Group to set of option ids (null handled specially)
      $byGroup = [];  // gid => ['values'=>set<int>, 'hasNull'=>bool]
      foreach ($pairs as [$g, $o]) {
        if (!isset($byGroup[$g])) $byGroup[$g] = ['values'=>[], 'hasNull'=>false];
        if ($o === null) $byGroup[$g]['hasNull'] = true;
        else $byGroup[$g]['values'][$o] = true;
      }

      // Validate: ensure each option belongs to its group
      $valCheck = $pdo->prepare("SELECT COUNT(1) FROM variation_values WHERE id=:vid AND group_id=:gid LIMIT 1");

      $ins = $pdo->prepare("
        INSERT INTO product_modifiers (product_id, modifier_group_id, default_option_id)
        VALUES (:p, :g, :o)
      ");

      foreach ($byGroup as $gid => $info) {
        $validVals = [];
        foreach (array_keys($info['values']) as $vid) {
          $valCheck->execute([':vid'=>$vid, ':gid'=>$gid]);
          if ((int)$valCheck->fetchColumn() === 1) { $validVals[] = (int)$vid; }
        }
        $validVals = array_values(array_unique($validVals));

        if (count($validVals) > 0) {
          // Insert one row per selected value
          foreach ($validVals as $vid) {
            $ins->bindValue(':p', $id, PDO::PARAM_INT);
            $ins->bindValue(':g', $gid, PDO::PARAM_INT);
            $ins->bindValue(':o', $vid, PDO::PARAM_INT);
            $ins->execute();
          }
        } else {
          // No valid values; if the user explicitly sent an empty selection for this group, record it with NULL
          if ($info['hasNull']) {
            $ins->bindValue(':p', $id, PDO::PARAM_INT);
            $ins->bindValue(':g', $gid, PDO::PARAM_INT);
            $ins->bindValue(':o', null, PDO::PARAM_NULL);
            $ins->execute();
          }
          // If group wasn't mentioned with null, we simply don't insert anything (group detached).
        }
      }
    }
  }

  $pdo->commit();
  $_SESSION['flash'] = 'Product saved successfully.';
  header('Location: /views/admin/products.php');
  exit;

} catch (Throwable $e) {
  if (!empty($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
  $_SESSION['flash'] = 'Save error. ' . $e->getMessage();
  header('Location: ' . ($id>0 ? '/views/admin/product_edit.php?id='.$id : '/views/admin/products_new.php'));
  exit;
}