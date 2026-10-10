<?php
declare(strict_types=1);

/**
 * Discover active site references for explicit Construction Suite mappings.
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/includes/suite-auth.php';
deliveries_suite_require_key();

$items=[];
try {require dirname(__DIR__).'/db.php';$s=$pdo->query('SELECT id,name FROM logistics_sites WHERE active=1 ORDER BY name');foreach($s->fetchAll(PDO::FETCH_ASSOC) as $site)$items[]=['id'=>(string)$site['id'],'name'=>$site['name']];}catch(PDOException $e){if((string)$e->getCode()!=='42S02'&&!((string)$e->getCode()==='HY000'&&str_contains($e->getMessage(),'no such table: logistics_sites')))throw $e;}
echo json_encode([
    'ok' => true,
    'module' => 'deliveries',
    'reference_type' => 'site',
    'items' => $items,
], JSON_UNESCAPED_SLASHES);
