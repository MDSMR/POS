<?php
// controllers/admin/modifiers_delete.php — Delete Modifier Group (tenant-scoped) + its values
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
use_backend_session();

$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];

$id=(int)($_GET['id']??0);
if($id<=0){ $_SESSION['flash']='Modifier group not specified.'; header('Location:/views/admin/modifiers.php'); exit; }

try{
  $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  // verify ownership
  $chk=$pdo->prepare("SELECT id FROM variation_groups WHERE id=:id AND tenant_id=:t LIMIT 1");
  $chk->execute([':id'=>$id, ':t'=>$tenantId]);
  if(!$chk->fetchColumn()){ $_SESSION['flash']='Group not found for this tenant.'; header('Location:/views/admin/modifiers.php'); exit; }

  $pdo->beginTransaction();
  $pdo->prepare("DELETE FROM variation_values WHERE group_id=:g")->execute([':g'=>$id]);
  $pdo->prepare("DELETE FROM variation_groups WHERE id=:g AND tenant_id=:t LIMIT 1")->execute([':g'=>$id, ':t'=>$tenantId]);
  $pdo->commit(); $_SESSION['flash']='Modifier group deleted.';
}catch(Throwable $e){
  if(!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash']='Delete error. '.$e->getMessage();
}
header('Location:/views/admin/modifiers.php'); exit;