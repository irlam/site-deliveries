<?php
// gateboard_data.php — JSON feed for today’s deliveries
declare(strict_types=1);
require_once __DIR__ . '/db.php';

date_default_timezone_set('Europe/London');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function site_origin(): string {
  $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
  $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
  return $scheme.'://'.$host;
}
function abs_url(string $u): string {
  return preg_match('~^https?://~i', $u) ? $u : site_origin().$u;
}
function gate_url(int $id): string {
  // Use new QR destination (same as everywhere else)
  return abs_url('/gate.php?id=' . $id . '&src=board');
}

$today = (new DateTime('today'))->format('Y-m-d');

try {
  // Pull *today’s* deliveries
  $sql = "SELECT id, supplier, user_name, material, quantity, unloading_method, status, due_datetime
          FROM deliveries
          WHERE DATE(due_datetime) = ?
          ORDER BY due_datetime ASC, id ASC";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([$today]);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'items'=>[], 'error'=>'DB error']);
  exit;
}

$now = new DateTime('now');
$out = [];

foreach ($rows as $r) {
  $id = (int)($r['id'] ?? 0);
  $dueIso = $r['due_datetime'] ?? null;
  $due = null; $dueHHMM = '';
  if ($dueIso) {
    try {
      $due = new DateTime($dueIso);
      $dueHHMM = $due->format('H:i');
    } catch (Throwable $e) {}
  }

  // Late logic: past due time and not Arrived/Completed/Cancelled
  $status = (string)($r['status'] ?? '');
  $statusLower = strtolower($status);
  $isTerminal  = (str_contains($statusLower,'completed') || str_contains($statusLower,'cancel'));
  $isArrived   = str_contains($statusLower,'arriv');
  $late = false;
  if ($due instanceof DateTime && !$isTerminal && !$isArrived) {
    $late = ($now > $due);
  }

  $out[] = [
    'id' => $id,
    'supplier' => (string)($r['supplier'] ?? ''),
    'user_name' => (string)($r['user_name'] ?? ''),
    'material' => (string)($r['material'] ?? ''),
    'quantity' => $r['quantity'],
    'unloading_method' => (string)($r['unloading_method'] ?? ''),
    'status' => $status ?: 'Booked in',
    'due_datetime' => $dueIso,
    'due_hhmm' => $dueHHMM,
    'late' => $late,
    'gate_url' => gate_url($id),
  ];
}

echo json_encode(['ok'=>true, 'items'=>$out], JSON_UNESCAPED_SLASHES);
