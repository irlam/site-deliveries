<?php
declare(strict_types=1);
require_once __DIR__.'/logistics-auth.php';
if(logistics_enabled($pdo)){
    header('Cache-Control: no-store');
    try{
        $legacyUser=logistics_user($pdo);
        if(!$legacyUser['admin']){
            $legacyScript=basename($_SERVER['SCRIPT_FILENAME']??'');
            if(!in_array($legacyScript,['upload_delivery_sheet.php','request_change.php'],true))Logistics::error('Use your private company calendar for deliveries.',403);
            (new Logistics($pdo))->delivery($legacyUser,(int)($_POST['delivery_id']??0));
        }
        if($_SERVER['REQUEST_METHOD']==='POST')logistics_write_check();
        if(in_array(basename($_SERVER['SCRIPT_FILENAME']??''),['book_delivery.php','book_delivery_multi.php','add_delivery.php'],true)&&$_SERVER['REQUEST_METHOD']==='POST'){
            require_once __DIR__.'/settings.php';$l=new Logistics($pdo);$g=$l->one('SELECT * FROM logistics_gates WHERE active=1 ORDER BY id LIMIT 1');if(!$g)Logistics::error('Configure the main gate first.');
            $input=$_POST;$input['site_id']=$g['site_id'];$input['gate_id']=$g['id'];$input['duration_min']=get_time_settings($pdo)['interval'];
            $method=(string)($input['unloading_method']??'Manual');
            if(in_array($method,['Crane','Forklift'],true)){$resources=$l->rows('SELECT r.* FROM logistics_resources r JOIN logistics_gate_resources l ON l.resource_id=r.id WHERE l.gate_id=? AND r.active=1 AND r.kind=?',[$g['id'],$method]);if(count($resources)!==1)Logistics::error('Choose the named crane or forklift on the gate calendar.',409);$input['resource_id']=$resources[0]['id'];}
            $slots=isset($input['slots'])?array_filter(array_map('trim',explode(',',str_replace("\n",',',$input['slots'])))):[$input['due_datetime']??''];if(count($slots)>100)Logistics::error('Book up to 100 slots at a time.');
            $created=0;$failed=[];foreach($slots as $slot){try{$l->save($legacyUser,array_replace($input,['due_datetime'=>$slot]));$created++;}catch(Throwable $e){$failed[]=$slot.': '.$e->getMessage();}}
            $message=($created?'Success: booked '.$created.' slot(s). ':'').implode(' | ',$failed);if(basename($_SERVER['SCRIPT_FILENAME']??'')==='add_delivery.php'){header('Location: /?legacy=1&msg='.rawurlencode($message));exit;}if(!$created)http_response_code(409);echo $message;exit;
        }
        if(in_array(basename($_SERVER['SCRIPT_FILENAME']??''),['move_delivery.php','edit_delivery.php'],true)&&$_SERVER['REQUEST_METHOD']==='POST'){
            $l=new Logistics($pdo);$id=(int)($_POST['id']??0);$d=$l->delivery($legacyUser,$id);$input=$_POST;$input['revision']=$d['logistics_revision'];
            if(isset($input['new_slot']))$input['due_datetime']=$input['new_slot'];
            if(empty($input['due_datetime']))unset($input['due_datetime']);
            $l->save($legacyUser,$input);echo 'success';exit;
        }
    }catch(Throwable $e){$status=(int)$e->getCode();http_response_code(in_array($status,[401,403,409,422],true)?$status:500);if($status===401&&$_SERVER['REQUEST_METHOD']==='GET'){header('Location: /company-login.php');exit;}header('Content-Type: application/json');echo json_encode(['ok'=>false,'error'=>$status>=400&&$status<500?$e->getMessage():'Request failed.']);exit;}
}
