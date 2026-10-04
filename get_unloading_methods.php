<?php
// get_unloading_methods.php — returns active unloading methods as JSON (ordered)
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

try {
    // Ensure table exists (safe if already present)
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS unloading_methods (
          id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
          label VARCHAR(100) NOT NULL UNIQUE,
          sort_order INT NOT NULL DEFAULT 0,
          is_active TINYINT(1) NOT NULL DEFAULT 1,
          created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->query("SELECT label FROM unloading_methods WHERE is_active = 1 ORDER BY sort_order ASC, label ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (!$rows) {
        $rows = ['Crane', 'Forklift', 'By hand'];
    }

    echo json_encode($rows, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error fetching unloading methods']);
}
