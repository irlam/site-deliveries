<?php
declare(strict_types=1);
require __DIR__.'/db.php';require __DIR__.'/includes/logistics-auth.php';
header('Cache-Control: no-store');header('Referrer-Policy: no-referrer');
try {
    logistics_start_session();
    if(($_SERVER['HTTPS']??'')!=='on'||($_SERVER['HTTP_HOST']??'')!=='sitedeliveries.site')Logistics::error('Use the secure Site Deliveries address.',403);
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='GET'){
        $instance=filter_var($_GET['instance_id']??null,FILTER_VALIDATE_INT);if(!$instance)Logistics::error('Open this site from Construction Suite.',400);
        $binding=DeliverySuite::binding($instance);DeliverySuite::logout();session_regenerate_id(true);
        $_SESSION=['delivery_pending'=>['instance_id'=>$instance,'state'=>bin2hex(random_bytes(32)),'expires'=>time()+60]];
        setcookie(session_name(),session_id(),['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'None']);
        header('Location: https://suite.defecttracker.uk/launch.php?instance_id='.$instance.'&state='.$_SESSION['delivery_pending']['state'],true,303);exit;
    }
    if($method!=='POST')Logistics::error('Method not allowed.',405);
    $pending=$_SESSION['delivery_pending']??null;unset($_SESSION['delivery_pending']);
    $state=$_POST['state']??null;$code=$_POST['code']??null;
    if(!is_array($pending)||$pending['expires']<time()||!is_string($state)||!is_string($code)||!preg_match('/^[a-f0-9]{64}$/D',$state)||!preg_match('/^[a-f0-9]{64}$/D',$code)||!hash_equals($pending['state'],$state))Logistics::error('Sign-in expired. Open this site from Suite again.',401);
    $binding=DeliverySuite::binding($pending['instance_id']);
    $identity=DeliverySuite::identity($binding,DeliverySuite::request($binding,'module-handoff',['code'=>$code,'state'=>$state]));
    $token=$identity['session_token']??null;if(!is_string($token)||!preg_match('/^[a-f0-9]{64}$/D',$token))Logistics::error('Invalid Suite session.',401);
    // Validate immediately before provisioning a local mapped identity.
    $identity=DeliverySuite::identity($binding,DeliverySuite::request($binding,'module-session',['action'=>'validate','session_token'=>$token]));
    DeliverySuite::user($pdo,$binding,$identity,true);session_regenerate_id(true);
    $_SESSION=['delivery_suite'=>['instance_id'=>$pending['instance_id'],'suite_user_id'=>$identity['user_id'],'token'=>$token],'logistics_csrf'=>bin2hex(random_bytes(32))];
    setcookie(session_name(),session_id(),['path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
    header('Location: /schedule.php?site='.$binding['local_site_id'],true,303);exit;
}catch(Throwable $error){
    if(isset($token,$binding)&&is_string($token))try{DeliverySuite::request($binding,'module-session',['action'=>'revoke','session_token'=>$token]);}catch(Throwable $ignored){}
    $_SESSION=[];http_response_code(in_array($error->getCode(),[400,401,403,405,503],true)?$error->getCode():503);echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Delivery sign-in</title><p>Delivery sign-in could not be completed. <a href="https://suite.defecttracker.uk/">Return to Suite and try again</a>.</p>';
}
