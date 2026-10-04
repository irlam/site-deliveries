<?php
// resolve_change_request.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

function bad(int $code, string $msg){
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg]);
  exit;
}

if (session_status() === PHP_SESSION_NONE) session_start();
$isAdmin = isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0;
if (!$isAdmin) bad(403, 'Admin only');

$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = trim($_POST['action'] ?? ''); // 'resolve' or 'decline'
if ($id <= 0) bad(400, 'Missing id');
if (!in_array($action, ['resolve','decline'], true)) bad(400, 'Invalid action');

$newStatus = $action === 'resolve' ? 'resolved' : 'declined';

try {
  $st = $pdo->prepare("UPDATE delivery_change_requests
                       SET status=?, resolved_at = CURRENT_TIMESTAMP
                       WHERE id=?");
  $st->execute([$newStatus, $id]);
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  bad(500, 'DB error: '.$e->getMessage());
}
