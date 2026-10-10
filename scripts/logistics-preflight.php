<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/db.php';
$columns=array_column($pdo->query('SHOW COLUMNS FROM deliveries')->fetchAll(PDO::FETCH_ASSOC),'Field');
$indexes=[];foreach($pdo->query('SHOW INDEX FROM deliveries')->fetchAll(PDO::FETCH_ASSOC)as $i)if(!(int)$i['Non_unique'])$indexes[$i['Key_name']][]=$i['Column_name'];
echo json_encode(['php'=>PHP_VERSION,'count'=>(int)$pdo->query('SELECT COUNT(*) FROM deliveries')->fetchColumn(),'unique'=>$indexes,'migrated'=>in_array('site_id',$columns,true),'writes'=>0],JSON_UNESCAPED_SLASHES)."\n";
