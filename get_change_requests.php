<?php
// get_change_requests.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$isAdmin = isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']) && $_SESSION['admin_id'] > 0;

// Only admins see request list
if (!$isAdmin) {
  echo json_encode(['ok'=>true, 'items'=>[]]);
  exit;
}

try {
  $sql = "SELECT r.*, d.supplier, d.user_name, d.material, d.due_datetime
          FROM delivery_change_requests r
          JOIN deliveries d ON d.id = r.delivery_id
          WHERE r.status='open'
          ORDER BY r.created_at DESC
          LIMIT 100";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

  // Shape items
  $items = array_map(function($r){
    return [
      'id'            => (int)$r['id'],
      'delivery_id'   => (int)$r['delivery_id'],
      'requester_name'=> $r['requester_name'],
      'contact'       => (string)($r['contact'] ?? ''),
      'request_type'  => $r['request_type'],
      'requested_dt'  => (string)($r['requested_dt'] ?? ''),
      'details'       => (string)($r['details'] ?? ''),
      'status'        => $r['status'],
      'created_at'    => $r['created_at'],
      'delivery'      => [
        'supplier'     => $r['supplier'],
        'user_name'    => $r['user_name'],
        'material'     => $r['material'],
        'due_datetime' => $r['due_datetime'],
      ]
    ];
  }, $rows);

  echo json_encode(['ok'=>true, 'items'=>$items]);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}
