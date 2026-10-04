<?php
// /push/unsubscribe.php
declare(strict_types=1);
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>false,'error'=>'Method Not Allowed']); exit;
}

$input = file_get_contents('php://input') ?: '';
$data = json_decode($input, true) ?: [];
$endpoint = (string)($data['endpoint'] ?? '');

if ($endpoint === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'Missing endpoint']); exit;
}

// delete from both possible tables if they exist
try {
  $tables = ['push_subscriptions','webpush_subscriptions'];
  foreach ($tables as $t) {
    try {
      $pdo->exec("DELETE FROM `$t` WHERE endpoint=".$pdo->quote($endpoint));
    } catch (Throwable $e) { /* ignore */ }
  }
  echo json_encode(['ok'=>true]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
