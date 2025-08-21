<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId = tenant_id();

$id          = (int)($_POST['id'] ?? 0);
$name        = trim($_POST['name'] ?? '');
$is_required = (int)($_POST['is_required'] ?? 0);
$min_select  = (int)($_POST['min_select'] ?? 0);
$max_select  = (int)($_POST['max_select'] ?? 1);
$sort_order  = (int)($_POST['sort_order'] ?? 0);
$is_active   = (int)($_POST['is_active'] ?? 1);

if ($name === '') {
  $_SESSION['flash'] = 'Name is required.';
  header('Location: '.base_url('views/admin/variations/index.php?'.($id?'act=edit_group&id='.$id:'act=new_group')));
  exit;
}

try {
  if ($id > 0) {
    $st = db()->prepare("UPDATE variation_groups SET name=:n,is_required=:r,min_select=:min,max_select=:max,
                         sort_order=:so,is_active=:ia,updated_at=NOW()
                         WHERE id=:id AND tenant_id=:t");
    $st->execute([':n'=>$name,':r'=>$is_required,':min'=>$min_select,':max'=>$max_select,':so'=>$sort_order,':ia'=>$is_active,':id'=>$id,':t'=>$tenantId]);
  } else {
    $st = db()->prepare("INSERT INTO variation_groups (tenant_id,name,is_required,min_select,max_select,sort_order,is_active)
                         VALUES (:t,:n,:r,:min,:max,:so,:ia)");
    $st->execute([':t'=>$tenantId,':n'=>$name,':r'=>$is_required,':min'=>$min_select,':max'=>$max_select,':so'=>$sort_order,':ia'=>$is_active]);
  }
  header('Location: '.base_url('views/admin/variations/index.php'));
} catch (Throwable $e) {
  $_SESSION['flash'] = 'Save failed.';
  header('Location: '.base_url('views/admin/variations/index.php'));
}