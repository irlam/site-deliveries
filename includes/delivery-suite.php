<?php
declare(strict_types=1);
/** Shared site capacity, explicit company ownership and fresh Suite identity. */
final class DeliverySuite {
    public static function binding(int $instance): array {
        $path=__DIR__.'/suite.local.php';
        $bindings=is_file($path)?(static fn(string $file):mixed=>require $file)($path):[];
        $binding=is_array($bindings)?($bindings[$instance]??null):null;
        if(!is_array($binding))Logistics::error('This Suite delivery connection is unavailable.',503);
        foreach(['instance_id','organization_id','project_id','local_site_id','local_company_id']as$field)
            if(!is_int($binding[$field]??null)||$binding[$field]<1)Logistics::error('Invalid Suite delivery binding.',503);
        if($binding['instance_id']!==$instance||!preg_match('/^[a-f0-9]{64}$/D',(string)($binding['key']??'')))Logistics::error('Invalid Suite delivery binding.',503);
        if(($binding['origin']??'')!=='https://sitedeliveries.site')Logistics::error('Invalid Suite delivery host.',503);
        return $binding;
    }
    public static function request(array $binding,string $endpoint,array $payload,?callable $transport=null): array {
        $payload['instance_id']=$binding['instance_id'];
        try {
            if($transport){$reply=$transport($endpoint,$payload,$binding['key']);}
            else {
                $body='';$ch=curl_init('https://suite.defecttracker.uk/api/v1/'.$endpoint.'.php');
                curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload,JSON_THROW_ON_ERROR),CURLOPT_FOLLOWLOCATION=>false,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-Construction-Suite-Key: '.$binding['key']],CURLOPT_WRITEFUNCTION=>static function($ch,$chunk)use(&$body){if(strlen($body)+strlen($chunk)>65536)return 0;$body.=$chunk;return strlen($chunk);}]);
                try{$ok=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);}finally{curl_close($ch);}
                if(!$ok||$status!==200)throw new RuntimeException();$reply=json_decode($body,true,16,JSON_THROW_ON_ERROR);
            }
            if(!is_array($reply)||($reply['ok']??false)!==true)throw new RuntimeException();return $reply;
        }catch(Throwable $error){Logistics::error('Suite access could not be verified. Sign in again.',401);}
    }
    public static function identity(array $binding,array $reply): array {
        $identity=$reply['identity']??null;
        if(!is_array($identity)||($identity['module_key']??'')!=='deliveries'||!is_int($identity['user_id']??null)||$identity['user_id']<1||!is_int($identity['session_expires_at']??null)||$identity['session_expires_at']<=time())Logistics::error('Invalid Suite identity.',401);
        foreach(['instance_id','organization_id','project_id','local_site_id','local_company_id']as$field)if(($identity[$field]??null)!==$binding[$field])Logistics::error('Suite project access denied.',403);
        if(!in_array($identity['role']??'',['platform_admin','admin','manager','site_manager','user','contractor','viewer'],true))Logistics::error('Suite role is unavailable.',403);
        if(!is_string($identity['name']??null)||trim($identity['name'])===''||strlen($identity['name'])>180)Logistics::error('Invalid Suite identity.',401);
        return $identity;
    }
    public static function user(PDO $db,array $binding,array $identity,bool $create=false): array {
        $site=$db->prepare('SELECT id FROM logistics_sites WHERE id=? AND active=1');$site->execute([$binding['local_site_id']]);
        $company=$db->prepare('SELECT id FROM logistics_companies WHERE id=? AND active=1');$company->execute([$binding['local_company_id']]);
        if(!$site->fetchColumn()||!$company->fetchColumn())Logistics::error('Site or company access was removed.',403);
        $query=$db->prepare('SELECT u.* FROM logistics_suite_users m JOIN logistics_users u ON u.id=m.local_user_id WHERE m.instance_id=? AND m.suite_user_id=?');
        $query->execute([$binding['instance_id'],$identity['user_id']]);$user=$query->fetch(PDO::FETCH_ASSOC);
        if(!$user&&$create){
            $db->beginTransaction();
            try {
                $email='suite-'.$binding['instance_id'].'-'.$identity['user_id'].'@deliveries.invalid';
                $query=$db->prepare("INSERT INTO logistics_users(company_id,name,email,password_hash,active,role) VALUES(?,?,?,?,1,'booker')");
                $query->execute([$binding['local_company_id'],$identity['name'],$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);$local=(int)$db->lastInsertId();
                $query=$db->prepare('INSERT INTO logistics_suite_users(instance_id,suite_user_id,local_user_id) VALUES(?,?,?)');$query->execute([$binding['instance_id'],$identity['user_id'],$local]);
                $query=$db->prepare('INSERT INTO logistics_memberships(user_id,site_id) VALUES(?,?)');$query->execute([$local,$binding['local_site_id']]);
                $db->commit();
            }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
            return self::user($db,$binding,$identity,false);
        }
        if(!$user||!(int)$user['active']||(int)$user['company_id']!==$binding['local_company_id'])Logistics::error('Delivery account access was removed.',403);
        $query=$db->prepare('SELECT user_id FROM logistics_memberships WHERE user_id=? AND site_id=?');$query->execute([$user['id'],$binding['local_site_id']]);
        if(!$query->fetchColumn())Logistics::error('Delivery site membership was removed.',403);
        return ['id'=>(int)$user['id'],'name'=>$identity['name'],'admin'=>false,'company_id'=>$binding['local_company_id'],'suite_site_id'=>$binding['local_site_id'],'role'=>($identity['role']==='viewer'||($user['role']??'')==='viewer')?'viewer':'booker'];
    }
    public static function current(PDO $db,?callable $transport=null): array {
        $auth=$_SESSION['delivery_suite']??null;
        try {
            if(!is_array($auth)||!is_int($auth['instance_id']??null)||!preg_match('/^[a-f0-9]{64}$/D',(string)($auth['token']??'')))Logistics::error('Sign in through Suite.',401);
            $binding=self::binding($auth['instance_id']);
            $identity=self::identity($binding,self::request($binding,'module-session',['action'=>'validate','session_token'=>$auth['token']],$transport));
            if(($auth['suite_user_id']??null)!==$identity['user_id'])Logistics::error('Suite account changed.',401);
            return self::user($db,$binding,$identity);
        }catch(Throwable $error){unset($_SESSION['delivery_suite'],$_SESSION['logistics_user_id'],$_SESSION['admin_id']);throw $error;}
    }
    public static function logout(): void {
        $auth=$_SESSION['delivery_suite']??null;unset($_SESSION['delivery_suite']);
        if(is_array($auth))try{self::request(self::binding($auth['instance_id']),'module-session',['action'=>'revoke','session_token'=>$auth['token']]);}catch(Throwable $ignored){}
    }
}
