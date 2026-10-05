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

require_once dirname(__DIR__) . '/includes/suite-auth.php';
deliveries_suite_require_key();

echo json_encode([
    'ok' => true,
    'module' => 'deliveries',
    'reference_type' => 'site',
    'items' => [],
], JSON_UNESCAPED_SLASHES);
