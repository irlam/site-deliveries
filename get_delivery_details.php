<?php
// get_delivery_details.php — JSON for a single delivery by id
declare(strict_types=1);

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=UTF-8');

// Optional: enable signed gate links by defining a secret once (e.g. in settings.php)
// define('GATE_SECRET', 'change-this-to-a-long-random-secret');

function hmac_b64url(string $data, string $key): string {
    $raw = hash_hmac('sha256', $data, $key, true);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function site_origin(): string {
    // Adjust if you serve on another origin or behind a proxy
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function gate_url_for_id(int $id): string {
    $u = '/gate.php?id=' . $id;
    if (defined('GATE_SECRET')) {
        $sig = hmac_b64url((string)$id, GATE_SECRET);
        $u  .= '&t=' . rawurlencode($sig);
    }
    return $u;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing or invalid id']);
    exit;
}

try {
    $sql = "
        SELECT
            d.id,
            d.supplier,
            d.user_name,
            d.material,
            d.quantity,
            d.driver,
            d.vehicle,
            d.unloading_method,
            d.status,
            DATE_FORMAT(d.due_datetime, '%Y-%m-%d %H:%i') AS due_datetime,
            DATE_FORMAT(d.created_at,  '%Y-%m-%d %H:%i') AS created_at
        FROM deliveries d
        WHERE d.id = ?
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    // For front-end convenience (your JS sometimes refers to "contractor")
    $row['contractor'] = $row['supplier'];

    // Add gate URL + QR image src for the modal’s right-hand side
    $relativeGate = gate_url_for_id((int)$row['id']);
    $absoluteGate = site_origin() . $relativeGate;
    $row['gate_url'] = $relativeGate;               // e.g. /gate.php?id=123[&t=...]
    $row['qr_src']   = '/icon.php?qr=' . rawurlencode($absoluteGate); // <img src="..." />

    echo json_encode($row);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
}
