<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/logistics-legacy.php';

require_once __DIR__ . '/includes/admin_auth.php';

header('Content-Type: text/plain; charset=UTF-8');

try {
    admin_require($pdo); // 403 for non-admins
} catch (Throwable $e) {
    http_response_code(403);
    echo 'Admins only.';
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo 'Invalid delivery id';
    exit;
}

$stmt = $pdo->prepare("UPDATE deliveries SET status='Cancelled' WHERE id = ?");
if ($stmt->execute([$id])) {
    echo 'success';
} else {
    http_response_code(500);
    echo 'Failed to cancel';
}
