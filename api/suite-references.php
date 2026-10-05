<?php
declare(strict_types=1);

/**
 * This installation currently uses a single deliveries calendar, not a
 * project/site field. Report no site mappings rather than inventing names.
 * Construction Suite can safely select "All data in this module".
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/db.php';

$expected = defined('CONSTRUCTION_SUITE_API_KEY')
    ? trim((string) CONSTRUCTION_SUITE_API_KEY)
    : trim((string) (getenv('CONSTRUCTION_SUITE_API_KEY') ?: ''));
$provided = trim((string) ($_SERVER['HTTP_X_CONSTRUCTION_SUITE_KEY'] ?? ''));

if (strlen($expected) < 32) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'suite_integration_not_configured']);
    exit;
}
if ($provided === '' || !hash_equals($expected, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

echo json_encode([
    'ok' => true,
    'module' => 'deliveries',
    'reference_type' => 'site',
    'items' => [],
], JSON_UNESCAPED_SLASHES);
