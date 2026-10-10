<?php
declare(strict_types=1);
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/includes/logistics-auth.php';
require dirname(__DIR__).'/includes/settings.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
try{
    $l=new Logistics($pdo);$u=logistics_user($pdo);$op=(string)($_GET['op']??'context');
    if(!logistics_enabled($pdo))Logistics::error('Gate scheduling is not enabled yet.',503);
    if($_SERVER['REQUEST_METHOD']==='GET'&&$op==='context'){try{$methods=$l->rows('SELECT label FROM unloading_methods WHERE is_active=1 ORDER BY sort_order,label');$methods=array_column($methods,'label');}catch(PDOException){$methods=[];}$methods=array_values(array_unique(array_merge($methods?:['By hand','Crane','Forklift'],['Manual','Tail-lift'])));}
    if($_SERVER['REQUEST_METHOD']==='POST'){
        logistics_write_check();$in=json_decode(file_get_contents('php://input'),true,32,JSON_THROW_ON_ERROR);if(!is_array($in))Logistics::error('Invalid request.');
        $result=match($op){'save'=>$l->save($u,$in),'cancel'=>(function()use($l,$u,$in){$l->cancel($u,(int)$in['id'],(int)$in['revision']);return [];} )(),default=>throw new RuntimeException('Unknown operation.',404)};
    }else{$result=match($op){'context'=>array_merge($l->context($u),['csrf'=>logistics_csrf(),'times'=>get_time_settings($pdo),'unloading_methods'=>$methods]),'calendar'=>['items'=>$l->calendar($u,(int)($_GET['site']??0),(string)($_GET['from']??''),(string)($_GET['to']??''))],'detail'=>(function()use($l,$u){$d=$l->delivery($u,(int)($_GET['id']??0));try{$files=$l->rows('SELECT path,label,created_at FROM delivery_files WHERE delivery_id=?',[$d['id']]);}catch(PDOException){$files=[];}return ['delivery'=>$d,'files'=>$files];})(),default=>throw new RuntimeException('Unknown operation.',404)};}
    echo json_encode(['ok'=>true]+$result,JSON_THROW_ON_ERROR);
}catch(Throwable $e){$code=$e->getCode();http_response_code(in_array($code,[401,403,404,405,409,422,503],true)?$code:500);echo json_encode(['ok'=>false,'error'=>$code>=400&&$code<600?$e->getMessage():'The request could not be completed.']);}
