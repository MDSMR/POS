<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId = tenant_id();

$id         = (int)($_POST['id'] ?? 0);
$name_en    = trim($_POST['name_en'] ?? '');
$name_ar    = trim($_POST['name_ar'] ?? '');
$parent_id  = ($_POST['parent_id'] ?? '') === '' ? null : (int)$_POST['parent_id'];
$sort_order = (int)($_POST['sort_order'] ?? 0);
$is_active  = (int)($_POST['is_active'] ?? 1);

if ($name_en === '') {
  $_SESSION['flash'] = 'Name (EN) is required.';
  header('Location: '.base_url('views/admin/categories/index.php'.($id?'?act=edit&id='.$id:'?act=new')));
  exit;
}

try {
  if ($id > 0) {
    $st = db()->prepare("UPDATE categories SET name_en=:ne,name_ar=:na,parent_id=:pid,sort_order=:so,is_active=:ia,updated_at=NOW()
                         WHERE id=:id AND tenant_id=:t");
    $st->execute([':ne'=>$name_en, ':na'=>$name_ar ?: null, ':pid'=>$parent_id, ':so'=>$sort_order, ':ia'=>$is_active, ':id'=>$id, ':t'=>$tenantId]);
  } else {
    $st = db()->prepare("INSERT INTO categories (tenant_id,name_en,name_ar,parent_id,sort_order,is_active) VALUES
                         (:t,:ne,:na,:pid,:so,:ia)");
    $st->execute([':t'=>$tenantId, ':ne'=>$name_en, ':na'=>$name_ar ?: null, ':pid'=>$parent_id, ':so'=>$sort_order, ':ia'=>$is_active]);
  }
  header('Location: '.base_url('views/admin/categories/index.php'));
} catch (Throwable $e) {
  $_SESSION['flash'] = 'Save failed.';
  header('Location: '.base_url('views/admin/categories/index.php'));
}