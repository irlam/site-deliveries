<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/db.php';

function bad(int $code, string $msg){
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  // Basic inputs
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

  // Normalise datetime-local "YYYY-MM-DDTHH:MM" -> "YYYY-MM-DD HH:MM:00"
  if ($requested_dt !== '') {
    $requested_dt = str_replace('T', ' ', $requested_dt);
    // If seconds missing, append :00
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}$/', $requested_dt)) {
      $requested_dt .= ':00';
    }
    // Validate final format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}$/', $requested_dt)) {
      bad(400, 'Invalid date/time format');
    }
  } else {
    $requested_dt = null;
  }

  // Make sure delivery exists (avoids foreign key surprises)
  $chk = $pdo->prepare('SELECT id FROM deliveries WHERE id=?');
  $chk->execute([$delivery_id]);
  if (!$chk->fetchColumn()) bad(404, 'Delivery not found');

  // Insert request
  $sql = "INSERT INTO delivery_change_requests
            (delivery_id, requester_name, contact, request_type, requested_dt, details, status)
          VALUES (?,?,?,?,?,?, 'open')";
  $st = $pdo->prepare($sql);
  $ok = $st->execute([
    $delivery_id,
    $requester_name,
    $contact !== '' ? $contact : null,
    $request_type,
    $requested_dt,
    $details !== '' ? $details : null
  ]);

  if (!$ok) {
    $err = $st->errorInfo();
    bad(500, 'DB insert failed: '.($err[2] ?? 'unknown error'));
  }

  echo json_encode(['ok'=>true, 'id'=>(int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  bad(500, 'Server error: '.$e->getMessage());
}
