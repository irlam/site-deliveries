<?php
// gate_action.php — marks arrived/completed (AJAX)
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? '';

if ($id <= 0 || !in_array($action, ['arrived','completed'], true)) {
  echo json_encode(['ok'=>false, 'error'=>'Bad request']); exit;
}

try{
  if ($action === 'arrived') {
    $stmt = $pdo->prepare("UPDATE deliveries
                           SET arrived_at = IFNULL(arrived_at, NOW()),
                               status = CASE WHEN status='Completed' THEN status ELSE 'Booked in' END
                           WHERE id=?");
    $stmt->execute([$id]);
    // TODO: fire your web-push “arrived” notification here if desired
  } else {
    $stmt = $pdo->prepare("UPDATE deliveries
                           SET completed_at = NOW(),
                               status = 'Completed'
                           WHERE id=?");
    $stmt->execute([$id]);
    // TODO: fire your web-push “completed” notification here if desired
  }
  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
