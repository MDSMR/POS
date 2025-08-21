<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$tenantId = tenant_id();

$id = (int)($_POST['id'] ?? 0);
$name = trim($_POST['name'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$email = trim($_POST['email'] ?? '');
$points = (int)($_POST['points_balance'] ?? 0);

if ($name === '') {
  header('Location: '.base_url('views/admin/customers/index.php'.($id?'?act=edit&id='.$id:'?act=new'))); exit;
}

try {
  if ($id>0) {
    $st = db()->prepare("UPDATE customers SET name=:n,phone=:p,email=:e,points_balance=:pb,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
    $st->execute([':n'=>$name,':p'=>$phone?:null,':e'=>$email?:null,':pb'=>$points,':id'=>$id,':t'=>$tenantId]);
  } else {
    $st = db()->prepare("INSERT INTO customers (tenant_id,name,phone,email,points_balance) VALUES (:t,:n,:p,:e,:pb)");
    $st->execute([':t'=>$tenantId,':n'=>$name,':p'=>$phone?:null,':e'=>$email?:null,':pb'=>$points]);
  }
} catch (Throwable $e) {}
header('Location: '.base_url('views/admin/customers/index.php'));