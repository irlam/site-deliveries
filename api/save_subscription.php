<?php
declare(strict_types=1);
error_reporting(E_ALL); ini_set('display_errors', '1');

require_once __DIR__ . '/../includes/functions.php'; // your PDO helper (adjust)
require_once __DIR__ . '/../includes/db.php';        // make sure this gives $pdo (adjust)

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data || empty($data['endpoint']) || empty($data['keys']['p256dh']) || empty($data['keys']['auth'])) {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'Invalid subscription']);
  exit;
}

$endpoint = $data['endpoint'];
$p256dh   = $data['keys']['p256dh'];
$auth     = $data['keys']['auth'];
$ua       = $_SERVER['HTTP_USER_AGENT'] ?? null;

$stmt = $pdo->prepare("
  INSERT INTO webpush_subscriptions (endpoint, p256dh, auth, ua)
  VALUES (:endpoint, :p256dh, :auth, :ua)
  ON DUPLICATE KEY UPDATE p256dh = VALUES(p256dh), auth = VALUES(auth), ua = VALUES(ua)
");
$stmt->execute([':endpoint'=>$endpoint, ':p256dh'=>$p256dh, ':auth'=>$auth, ':ua'=>$ua]);

echo json_encode(['ok' => true]);
