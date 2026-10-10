<?php
// submit_change_request.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';


function bad(int $code, string $msg){
  http_response_code($code);
  echo json_encode(['ok'=>false, 'error'=>$msg]);
  exit;
}

$delivery_id    = isset($_POST['delivery_id']) ? (int)$_POST['delivery_id'] : 0;
$requester_name = trim($_POST['requester_name'] ?? '');
$contact        = trim($_POST['contact'] ?? '');
$request_type   = trim($_POST['request_type'] ?? '');
$requested_dt   = trim($_POST['requested_dt'] ?? '');
$details        = trim($_POST['details'] ?? '');

if ($delivery_id <= 0) bad(400, 'Missing delivery_id');
if ($requester_name === '') bad(400, 'Please enter your name');
if (!in_array($request_type, ['change_time','cancel','edit_details'], true)) {
  bad(400, 'Invalid request type');
}
$requested_dt_sql = null;
if ($request_type === 'change_time') {
  // Accept "YYYY-MM-DD HH:MM" or HTML datetime-local “YYYY-MM-DDTHH:MM”
  $requested_dt = str_replace('T', ' ', $requested_dt);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $requested_dt)) {
    bad(400, 'Please choose a new date/time');
  }
  $requested_dt_sql = $requested_dt . ':00';
}

try {
  // Optional: verify delivery exists
  $st = $pdo->prepare('SELECT id FROM deliveries WHERE id=?');
  $st->execute([$delivery_id]);
  if (!$st->fetch()) bad(404, 'Delivery not found');

  $st = $pdo->prepare('INSERT INTO delivery_change_requests
    (delivery_id, requester_name, contact, request_type, requested_dt, details)
    VALUES (?,?,?,?,?,?)');
  $st->execute([
    $delivery_id,
    $requester_name,
    $contact !== '' ? $contact : null,
    $request_type,
    $requested_dt_sql,
    $details !== '' ? $details : null
  ]);

  echo json_encode(['ok'=>true, 'msg'=>'Request submitted']);
} catch (Throwable $e) {
  bad(500, 'DB error: '.$e->getMessage());
}
