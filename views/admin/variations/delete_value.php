<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth.php';
auth_require_login();

$group_id = (int)($_GET['group_id'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
if ($id > 0) {
  try {
    $d = db()->prepare("DELETE FROM variation_values WHERE id=:id AND group_id=:g");
    $d->execute([':id'=>$id, ':g'=>$group_id]);
  } catch (Throwable $e) {}
}
header('Location: '.base_url('views/admin/variations/index.php?group_id='.$group_id));