<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/includes/suite-auth.php';
deliveries_suite_require_key();

try {
    require_once dirname(__DIR__) . '/db.php';
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Deliveries database is unavailable.');
    }

    [$scopeSql,$scopeArgs]=deliveries_suite_scope($pdo);
    $tz = new DateTimeZone('Europe/London');
    $now = new DateTimeImmutable('now', $tz);
    $start = $now->setTime(0, 0);
    $end = $start->modify('+1 day');

    $sql = "
        SELECT
            COUNT(*) AS total,
            COALESCE(SUM(
                CASE WHEN LOWER(status) <> 'cancelled'
                THEN 1 ELSE 0 END
            ), 0) AS today,
            COALESCE(SUM(
                CASE WHEN LOWER(status) <> 'cancelled'
                     AND arrived_at IS NULL
                     AND completed_at IS NULL
                     AND COALESCE(no_show, 0) = 0
                THEN 1 ELSE 0 END
            ), 0) AS awaiting_arrival,
            COALESCE(SUM(
                CASE WHEN completed_at IS NOT NULL
                     OR LOWER(status) = 'completed'
                THEN 1 ELSE 0 END
            ), 0) AS completed,
            COALESCE(SUM(
                CASE WHEN LOWER(status) <> 'cancelled'
                     AND arrived_at IS NULL
                     AND completed_at IS NULL
                     AND COALESCE(no_show, 0) = 0
                     AND due_datetime < ?
                THEN 1 ELSE 0 END
            ), 0) AS overdue,
            COALESCE(SUM(
                CASE WHEN COALESCE(no_show, 0) = 1
                THEN 1 ELSE 0 END
            ), 0) AS no_shows
        FROM deliveries
        WHERE due_datetime >= ?
          AND due_datetime < ?
    ";

    $stmt = $pdo->prepare($sql.$scopeSql);
    $stmt->execute(array_merge([
        $now->format('Y-m-d H:i:s'),
        $start->format('Y-m-d H:i:s'),
        $end->format('Y-m-d H:i:s')
    ],$scopeArgs));

    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $metrics = [];
    foreach ([
        'total', 'today', 'awaiting_arrival',
        'completed', 'overdue', 'no_shows'
    ] as $field) {
        $metrics[$field] = (int) ($row[$field] ?? 0);
    }

    echo json_encode([
        'ok' => true,
        'module' => 'deliveries',
        'metrics' => $metrics,
        'last_updated' => $now->format(DATE_ATOM)
    ]);
} catch (Throwable $e) {
    error_log('Suite deliveries: ' . $e->getMessage());
    http_response_code(503);
    echo json_encode([
        'ok' => false,
        'error' => 'summary_unavailable'
    ]);
}
