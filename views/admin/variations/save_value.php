<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$group_id    = (int)($_POST['group_id'] ?? 0);
$id          = (int)($_POST['id'] ?? 0);
$value_en    = trim($_POST['value_en'] ?? '');
$value_ar    = trim($_POST['value_ar'] ?? '');
$price_delta = (float)($_POST['price_delta'] ?? 0);
$sort_order  = (int)($_POST['sort_order'] ?? 0);
$is_active   = (int)($_POST['is_active'] ?? 1);

if ($group_id <= 0 || $value_en === '') {
  $_SESSION['flash'] = 'Value (EN) is required.';
  header('Location: '.base_url('views/admin/variations/index.php?group_id='.$group_id.($id?'&act=edit_value&id='.$id:'&act=new_value')));
  exit;
}

try {
  if ($id > 0) {
    $st = db()->prepare("UPDATE variation_values SET value_en=:ve,value_ar=:va,price_delta=:pd,sort_order=:so,is_active=:ia,updated_at=NOW()
                         WHERE id=:id AND group_id=:g");
    $st->execute([':ve'=>$value_en,':va'=>$value_ar ?: null,':pd'=>$price_delta,':so'=>$sort_order,':ia'=>$is_active,':id'=>$id,':g'=>$group_id]);
  } else {
    $st = db()->prepare("INSERT INTO variation_values (group_id,value_en,value_ar,price_delta,sort_order,is_active)
                         VALUES (:g,:ve,:va,:pd,:so,:ia)");
    $st->execute([':g'=>$group_id,':ve'=>$value_en,':va'=>$value_ar ?: null,':pd'=>$price_delta,':so'=>$sort_order,':ia'=>$is_active]);
  }
  header('Location: '.base_url('views/admin/variations/index.php?group_id='.$group_id));
} catch (Throwable $e) {
  $_SESSION['flash'] = 'Save failed.';
  header('Location: '.base_url('views/admin/variations/index.php?group_id='.$group_id));
}