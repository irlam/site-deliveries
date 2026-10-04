<?php
// /admin/update_request_status.php
declare(strict_types=1);

header('Cache-Control: no-store');

require_once __DIR__ . '/../db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$is_admin = isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$is_admin) {
  http_response_code(403);
  echo 'Access denied';
  exit;
}

$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = $_POST['action'] ?? '';

if ($id <= 0 || !in_array($action, ['resolve','decline'], true)) {
  http_response_code(400);
  echo 'Invalid input';
  exit;
}

$status = ($action === 'resolve') ? 'resolved' : 'declined';

try {
  // Only update if it's still open
  $stmt = $pdo->prepare("
    UPDATE delivery_change_requests
       SET status = ?, resolved_at = NOW()
     WHERE id = ? AND status = 'open'
  ");
  $stmt->execute([$status, $id]);

  // Return a simple OK for fetch(); your JS reloads the page after res.ok
  header('Content-Type: text/plain; charset=utf-8');
  echo 'OK';
} catch (Throwable $e) {
  http_response_code(500);
  echo 'DB error';
}
