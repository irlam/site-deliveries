<?php
declare(strict_types=1);

/**
 * Authenticate read-only Construction Suite endpoints without connecting to
 * the deliveries database. Put the key in a Plesk environment variable or
 * the untracked includes/suite.local.php file. Never send it in the URL.
 */
function deliveries_suite_require_key(): void
{
    $local = __DIR__ . '/suite.local.php';
    if (is_file($local)) {
        require_once $local;
    }

    $expected = defined('CONSTRUCTION_SUITE_API_KEY')
        ? trim((string) CONSTRUCTION_SUITE_API_KEY)
        : trim((string) (
            getenv('CONSTRUCTION_SUITE_API_KEY')
            ?: getenv('SUITE_INTEGRATION_KEY')
            ?: ''
        ));
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
}
