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

/** Site-scoped reporting after the logistics migration; old installations keep their contract. */
function deliveries_suite_scope(PDO $pdo): array
{
    try {$pdo->query('SELECT site_id FROM deliveries LIMIT 0');} catch (PDOException $e) {
        if ((string)$e->getCode() === '42S22' || ((string)$e->getCode() === 'HY000' && str_contains($e->getMessage(), 'no such column: site_id'))) return ['', []];
        throw $e;
    }
    $site = filter_var($_GET['site'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$site) {http_response_code(422);echo json_encode(['ok'=>false,'error'=>'site_reference_required']);exit;}
    $s=$pdo->prepare('SELECT id FROM logistics_sites WHERE id=? AND active=1');$s->execute([$site]);
    if (!$s->fetchColumn()) {http_response_code(404);echo json_encode(['ok'=>false,'error'=>'site_not_found']);exit;}
    $sql=' AND site_id=?';$args=[$site];
    if(defined('CONSTRUCTION_SUITE_COMPANY_ID')){$sql.=' AND company_id=?';$args[]=(int)CONSTRUCTION_SUITE_COMPANY_ID;}
    return [$sql,$args];
}
