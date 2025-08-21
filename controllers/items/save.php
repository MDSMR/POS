<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId = tenant_id();

$id            = (int)($_POST['id'] ?? 0);
$name_en       = trim($_POST['name_en'] ?? '');
$name_ar       = trim($_POST['name_ar'] ?? '');
$price         = (float)($_POST['price'] ?? 0);
$is_open_price = (int)($_POST['is_open_price'] ?? 0);
$pos_visible   = (int)($_POST['pos_visible'] ?? 1);
$is_active     = (int)($_POST['is_active'] ?? 1);
$cat_ids       = $_POST['category_ids'] ?? [];

if ($name_en === '') {
  $_SESSION['flash'] = 'Name (EN) is required.';
  header('Location: '.base_url('views/admin/items/index.php'.($id?'?act=edit&id='.$id:'?act=new')));
  exit;
}

try {
  db()->beginTransaction();
  if ($id > 0) {
    $st = db()->prepare("UPDATE products SET name_en=:ne,name_ar=:na,price=:pr,is_open_price=:op,pos_visible=:pv,is_active=:ia,updated_at=NOW()
                         WHERE id=:id AND tenant_id=:t");
    $st->execute([':ne'=>$name_en, ':na'=>$name_ar ?: null, ':pr'=>$price, ':op'=>$is_open_price, ':pv'=>$pos_visible, ':ia'=>$is_active, ':id'=>$id, ':t'=>$tenantId]);
  } else {
    $st = db()->prepare("INSERT INTO products (tenant_id,name_en,name_ar,price,is_open_price,pos_visible,is_active)
                         VALUES (:t,:ne,:na,:pr,:op,:pv,:ia)");
    $st->execute([':t'=>$tenantId, ':ne'=>$name_en, ':na'=>$name_ar ?: null, ':pr'=>$price, ':op'=>$is_open_price, ':pv'=>$pos_visible, ':ia'=>$is_active]);
    $id = (int)db()->lastInsertId();
  }

  // Update categories
  $del = db()->prepare("DELETE FROM product_categories WHERE product_id=:pid");
  $del->execute([':pid'=>$id]);
  if (is_array($cat_ids) && $cat_ids) {
    $ins = db()->prepare("INSERT IGNORE INTO product_categories (product_id,category_id) VALUES (:pid,:cid)");
    foreach ($cat_ids as $cid) {
      $cid = (int)$cid; if ($cid<=0) continue;
      $ins->execute([':pid'=>$id, ':cid'=>$cid]);
    }
  }

  db()->commit();
  header('Location: '.base_url('views/admin/items/index.php'));
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  $_SESSION['flash'] = 'Save failed.';
  header('Location: '.base_url('views/admin/items/index.php'));
}