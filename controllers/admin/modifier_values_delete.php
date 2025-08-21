<?php
// controllers/admin/modifier_values_delete.php — Delete single value (tenant-scoped through its group)
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
use_backend_session();

$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];

$id=(int)($_GET['id']??0);
if($id<=0){ $_SESSION['flash']='Value not specified.'; header('Location:/views/admin/modifier_values.php'); exit; }

try{
  $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  // find group & verify tenant
  $st=$pdo->prepare("SELECT vv.group_id, vg.tenant_id FROM variation_values vv JOIN variation_groups vg ON vg.id=vv.group_id WHERE vv.id=:id LIMIT 1");
  $st->execute([':id'=>$id]); $row=$st->fetch();
  if(!$row || (int)$row['tenant_id'] !== $tenantId){ $_SESSION['flash']='Not allowed for this tenant.'; header('Location:/views/admin/modifier_values.php'); exit; }
  $gid=(int)$row['group_id'];
  $pdo->prepare("DELETE FROM variation_values WHERE id=:id LIMIT 1")->execute([':id'=>$id]);
  $_SESSION['flash']='Modifier value deleted.';
  header('Location:/views/admin/modifier_values.php?group='.$gid); exit;
}catch(Throwable $e){
  $_SESSION['flash']='Delete error. '.$e->getMessage();
  header('Location:/views/admin/modifier_values.php'); exit;
}