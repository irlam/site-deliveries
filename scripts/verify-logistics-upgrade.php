<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/db.php';require dirname(__DIR__).'/includes/logistics-auth.php';
$backups=glob(dirname(dirname(__DIR__)).'/logistics-backups/*/complete.json');rsort($backups);$baseline=null;foreach($backups as $path){$m=json_decode(file_get_contents($path),true);if(isset($m['history_sha256'])){$baseline=$m;break;}}
if(!$baseline)throw new RuntimeException('A verified history baseline is required.');
$columns=array_map(fn($c)=>'`'.str_replace('`','``',$c).'`',$baseline['original_columns']);$history=$pdo->query('SELECT '.implode(',',$columns).' FROM deliveries ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$unchanged=hash_equals($baseline['history_sha256'],hash('sha256',json_encode($history,JSON_THROW_ON_ERROR)));
$sites=$pdo->query('SELECT COUNT(*) FROM logistics_sites')->fetchColumn();$unmapped=$pdo->query('SELECT COUNT(*) FROM deliveries WHERE site_id IS NULL OR gate_id IS NULL')->fetchColumn();
echo json_encode(['enabled'=>logistics_enabled($pdo),'count'=>count($history),'history_unchanged'=>$unchanged,'unmapped'=>(int)$unmapped,'sites'=>(int)$sites]).PHP_EOL;
if(!$unchanged||$unmapped||!logistics_enabled($pdo))exit(1);
