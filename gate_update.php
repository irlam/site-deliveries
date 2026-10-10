<?php
// gate_update.php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';

require_once __DIR__ . '/includes/push.php'; // uses your existing vendor/minishlink/web-push

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$id     = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$action = isset($_POST['action']) ? strtolower(trim($_POST['action'])) : '';

if ($id <= 0 || !in_array($action, ['arrived','completed'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid payload']);
    exit;
}

// Load the delivery (and verify it exists)
$st = $pdo->prepare("SELECT * FROM deliveries WHERE id = ?");
$st->execute([$id]);
$delivery = $st->fetch(PDO::FETCH_ASSOC);

if (!$delivery) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Delivery not found']);
    exit;
}

try {
    if ($action === 'arrived') {
        // If your table doesn’t have arrived_at yet, create it once in SQL or comment this out
        $pdo->prepare("UPDATE deliveries SET arrived_at = NOW(), status = IF(status='Cancelled', status, status) WHERE id = ?")->execute([$id]);
    } else { // completed
        // If your table doesn’t have completed_at yet, create it once in SQL or comment this out
        $pdo->prepare("UPDATE deliveries SET completed_at = NOW(), status = 'Completed' WHERE id = ?")->execute([$id]);
    }

    // Refresh delivery after update to include new timestamps/status
    $st = $pdo->prepare("SELECT * FROM deliveries WHERE id = ?");
    $st->execute([$id]);
    $delivery = $st->fetch(PDO::FETCH_ASSOC);

    // Broadcast push to all subscribers (uses your existing Minishlink/WebPush)
    $payload = [
        'type'         => $action,                   // 'arrived' | 'completed'
        'delivery_id'  => (int)$delivery['id'],
        'supplier'     => logistics_enabled($pdo) ? 'Site delivery' : ($delivery['supplier'] ?? ''),
        'material'     => $delivery['material'] ?? '',
        'quantity'     => $delivery['quantity'] ?? '',
        'due_datetime' => $delivery['due_datetime'] ?? '',
        'status'       => $delivery['status'] ?? '',
        'ts'           => time(),
    ];
    // This function is defined in includes/push.php (already present in your site)
    if(logistics_enabled($pdo))$payload=['type'=>$action,'title'=>'Site Deliveries','body'=>'A delivery status has changed. Sign in to view your bookings.','url'=>'/schedule.php','ts'=>time()];
    if(function_exists('broadcast_delivery_event'))broadcast_delivery_event($pdo, $payload);

    echo json_encode(['ok' => true, 'delivery' => $delivery]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed']);
}
