<?php
// get_week_deliveries.php — JSON for all deliveries in a given week (Mon–Sun)
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';


header('Content-Type: application/json; charset=UTF-8');

// Expect ?week=YYYY-MM-DD (any day is okay; we'll normalise to Monday)
$weekStr = isset($_GET['week']) ? trim((string)$_GET['week']) : '';
if ($weekStr === '') {
    // Default: this week's Monday (Europe/London)
    date_default_timezone_set('Europe/London');
    $today  = new DateTimeImmutable('today');
    $dow    = (int)$today->format('N'); // 1=Mon..7=Sun
    $monday = $today->modify('-' . ($dow - 1) . ' days');
} else {
    $t = DateTimeImmutable::createFromFormat('Y-m-d', $weekStr, new DateTimeZone('Europe/London'));
    if (!$t) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid date. Use YYYY-MM-DD.']);
        exit;
    }
    $dow    = (int)$t->format('N');
    $monday = $t->modify('-' . ($dow - 1) . ' days');
}

// Compute week range [monday 00:00:00, next monday 00:00:00)
$start = $monday->setTime(0, 0, 0);
$end   = $start->modify('+7 days');

try {
    $sql = "
        SELECT
            id,
            supplier,
            user_name,
            material,
            quantity,
            unloading_method,
            status,
            DATE_FORMAT(due_datetime, '%Y-%m-%d %H:%i') AS due_datetime
        FROM deliveries
        WHERE due_datetime >= ? AND due_datetime < ?
        ORDER BY due_datetime ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$start->format('Y-m-d H:i:s'), $end->format('Y-m-d H:i:s')]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Return array (calendar code expects flat list)
    echo json_encode($rows ?: []);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error fetching week deliveries.']);
}
