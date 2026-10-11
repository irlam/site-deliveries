<?php
declare(strict_types=1);
header('Content-Type: application/json');header('Cache-Control: no-store');
try{
 require_once dirname(__DIR__).'/includes/logistics.php';require_once dirname(__DIR__).'/includes/delivery-suite.php';
 if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){http_response_code(405);echo '{"ok":false}';exit;}
 $id=$_GET['instance_id']??'';
 if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,9}$/D',$id)){http_response_code(400);echo '{"ok":false}';exit;}
 $b=DeliverySuite::binding((int)$id);
 if(!hash_equals($b['key'],(string)($_SERVER['HTTP_X_CONSTRUCTION_SUITE_KEY']??''))){http_response_code(401);echo '{"ok":false}';exit;}
 foreach(['site'=>'local_site_id','company'=>'local_company_id'] as $param=>$field)if(isset($_GET[$param])&&(string)$_GET[$param]!==(string)$b[$field]){http_response_code(403);echo '{"ok":false}';exit;}
 require dirname(__DIR__).'/db.php';
 $today=(new DateTimeImmutable('today',new DateTimeZone('Europe/London')))->format('Y-m-d H:i:s');
 $tomorrow=(new DateTimeImmutable('tomorrow',new DateTimeZone('Europe/London')))->format('Y-m-d H:i:s');
 $q=$pdo->prepare("SELECT COUNT(*) total,COALESCE(SUM(due_datetime>=? AND due_datetime<? AND LOWER(status)<>'cancelled'),0) today FROM deliveries WHERE site_id=? AND company_id=?");$q->execute([$today,$tomorrow,$b['local_site_id'],$b['local_company_id']]);
 echo json_encode(['ok'=>true,'module'=>'deliveries','scope'=>['site'=>$b['local_site_id'],'company'=>$b['local_company_id']],'metrics'=>array_map('intval',$q->fetch(PDO::FETCH_ASSOC))],JSON_THROW_ON_ERROR);
}catch(Throwable $e){http_response_code(503);echo '{"ok":false,"error":"summary_unavailable"}';}
