<?php
declare(strict_types=1);

/** Gate reservations are serialized by site, then checked by full time interval. */
final class Logistics
{
    public function __construct(public readonly PDO $db) {}
    public function rows(string $sql, array $args=[]): array {$s=$this->db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);}
    public function one(string $sql, array $args=[]): ?array {return $this->rows($sql,$args)[0]??null;}
    public function run(string $sql,array $args=[]): void {$s=$this->db->prepare($sql);$s->execute($args);}
    public static function error(string $message,int $code=422): never {throw new RuntimeException($message,$code);}
    public function site(array $user,int $id): array {
        $site=$this->one('SELECT * FROM logistics_sites WHERE id=? AND active=1',[$id]);
        if(!$site)self::error('Site not found.',404);
        if(!$user['admin']&&!$this->one('SELECT 1 FROM logistics_memberships WHERE user_id=? AND site_id=?',[$user['id'],$id]))self::error('Site access denied.',403);
        return $site;
    }
    public function delivery(array $user,int $id): array {
        $d=$this->one('SELECT * FROM deliveries WHERE id=?',[$id]);if(!$d)self::error('Delivery not found.',404);
        $this->site($user,(int)$d['site_id']);
        if(!$user['admin']&&(int)$d['company_id']!==(int)$user['company_id'])self::error('Delivery access denied.',403);
        return $d;
    }
    public function context(array $u): array {
        $sites=$u['admin']?$this->rows('SELECT * FROM logistics_sites WHERE active=1 ORDER BY name'):$this->rows('SELECT s.* FROM logistics_sites s JOIN logistics_memberships m ON m.site_id=s.id WHERE m.user_id=? AND s.active=1 ORDER BY s.name',[$u['id']]);
        $ids=array_map('intval',array_column($sites,'id'));$in=$ids?implode(',',$ids):'0';
        return ['user'=>['id'=>$u['id'],'name'=>$u['name'],'admin'=>$u['admin'],'company_id'=>$u['company_id']], 'sites'=>$sites,'gates'=>$this->rows("SELECT * FROM logistics_gates WHERE active=1 AND site_id IN ($in) ORDER BY name"),'resources'=>$this->rows("SELECT * FROM logistics_resources WHERE active=1 AND site_id IN ($in) ORDER BY name"),'links'=>$this->rows("SELECT l.* FROM logistics_gate_resources l JOIN logistics_gates g ON g.id=l.gate_id WHERE g.site_id IN ($in)"),'companies'=>$u['admin']?$this->rows('SELECT * FROM logistics_companies WHERE active=1 ORDER BY name'):$this->rows('SELECT * FROM logistics_companies WHERE id=? AND active=1',[$u['company_id']])];
    }
    public function calendar(array $u,int $site,string $from,string $to): array {
        $this->site($u,$site);
        $a=DateTimeImmutable::createFromFormat('!Y-m-d',$from);$b=DateTimeImmutable::createFromFormat('!Y-m-d',$to);
        if(!$a||!$b||$a->format('Y-m-d')!==$from||$b->format('Y-m-d')!==$to||$b<=$a||$b>$a->modify('+32 days'))self::error('Invalid calendar range.');
        $rows=$this->rows("SELECT d.*,g.name AS gate_name,r.name AS resource_name,c.name AS company_name FROM deliveries d LEFT JOIN logistics_gates g ON g.id=d.gate_id LEFT JOIN logistics_resources r ON r.id=d.resource_id LEFT JOIN logistics_companies c ON c.id=d.company_id WHERE d.site_id=? AND d.due_datetime>=? AND d.due_datetime<? AND LOWER(d.status)<>'cancelled' ORDER BY d.due_datetime,d.id",[$site,$from.' 00:00:00',$to.' 00:00:00']);
        foreach($rows as &$d){
            if(!$u['admin']&&(int)$d['company_id']!==(int)$u['company_id']){
                // Availability is a separate minimal representation; no ID or contact data.
                $d=['private'=>true,'gate_id'=>(int)$d['gate_id'],'resource_id'=>$d['resource_id']?(int)$d['resource_id']:null,'due_datetime'=>$d['due_datetime'],'duration_min'=>(int)($d['duration_min']?:20),'material'=>'Unavailable'];
            }else{$d['private']=false;}
        }unset($d);return $rows;
    }
    public function audit(array $u,int $site,string $event,?int $delivery,array $details=[]): void {$this->run('INSERT INTO logistics_audit(site_id,actor,event,delivery_id,details,created_at) VALUES(?,?,?,?,?,?)',[$site,($u['admin']?'admin:':'user:').$u['id'],$event,$delivery,json_encode($details,JSON_THROW_ON_ERROR),date('Y-m-d H:i:s')]);}
    public function save(array $u,array $in): array {
        $id=(int)($in['id']??0);$old=$id?$this->delivery($u,$id):null;
        if($old&&in_array(strtolower($old['status']),['completed','cancelled'],true))self::error('Completed or cancelled deliveries cannot be rescheduled.',409);
        $site=(int)($old['site_id']??$in['site_id']??0);$this->site($u,$site);
        $company=$u['admin']?(int)($in['company_id']??$old['company_id']??0):(int)$u['company_id'];
        if($company&&!$this->one('SELECT id FROM logistics_companies WHERE id=? AND active=1',[$company]))self::error('Choose an active company.');
        if(!$u['admin']&&!$company)self::error('Company access is not configured.',403);
        $gate=(int)($in['gate_id']??$old['gate_id']??0);$resource=(int)($in['resource_id']??$old['resource_id']??0);
        $g=$this->one('SELECT * FROM logistics_gates WHERE id=? AND site_id=? AND active=1',[$gate,$site]);if(!$g)self::error('Choose a gate for this site.');
        $r=$resource?$this->one('SELECT r.* FROM logistics_resources r JOIN logistics_gate_resources l ON l.resource_id=r.id WHERE r.id=? AND r.site_id=? AND l.gate_id=? AND r.active=1',[$resource,$site,$gate]):null;
        if($resource&&!$r)self::error('This equipment is not available at that gate.');
        $due=str_replace('T',' ',(string)($in['due_datetime']??$old['due_datetime']??''));$due=substr($due,0,16);
        $date=DateTimeImmutable::createFromFormat('!Y-m-d H:i',$due);if(!$date||$date->format('Y-m-d H:i')!==$due)self::error('Choose a valid delivery date and time.');
        $duration=(int)($in['duration_min']??$old['duration_min']??20);if($duration<5||$duration>480||$duration%5)self::error('Duration must be 5–480 minutes, in 5-minute steps.');
        $settings=function_exists('get_time_settings')?get_time_settings($this->db):['start'=>'08:00','end'=>'18:00','interval'=>20];
        $minutes=static fn(string $t)=>(int)substr($t,0,2)*60+(int)substr($t,3,2);
        $start=$minutes($settings['start']);$end=$minutes($settings['end']);$at=$minutes($date->format('H:i'));$step=max(1,(int)$settings['interval']);
        if($at<$start||$at+$duration>$end||($at-$start)%$step)self::error('Booking must fit the site opening hours and slot interval.');
        $finish=$date->modify('+'.$duration.' minutes')->format('Y-m-d H:i:s');$due=$date->format('Y-m-d H:i:s');
        $fields=[];foreach(['supplier','material','quantity','user_name']as $f){$v=trim((string)($in[$f]??$old[$f]??''));if($v===''||strlen($v)>255)self::error('Complete '.$f.'.');$fields[$f]=$v;}
        if(!$u['admin']){$c=$this->one('SELECT name FROM logistics_companies WHERE id=?',[$company]);$fields['supplier']=$c['name'];$fields['user_name']=$u['name'];}
        $method=(string)($r['kind']??$in['unloading_method']??$old['unloading_method']??'Manual');if($method===''||strlen($method)>120)self::error('Choose a valid unloading method.');
        if(in_array(strtolower(trim($method)),['crane','forklift'],true)&&!$resource)self::error('Choose the named crane or forklift.');
        $this->db->beginTransaction();try{
            $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $this->one('SELECT id FROM logistics_sites WHERE id=?'.$lock,[$site]);
            if($old){$fresh=$this->one('SELECT * FROM deliveries WHERE id=?'.$lock,[$id]);if((int)($in['revision']??0)!==(int)$fresh['logistics_revision'])self::error('This booking changed. Refresh before editing.',409);if(in_array(strtolower($fresh['status']),['completed','cancelled'],true))self::error('Booking is no longer editable.',409);}
            $busy=$this->rows("SELECT id,gate_id,resource_id,due_datetime,duration_min FROM deliveries WHERE site_id=? AND id<>? AND LOWER(status) NOT IN ('cancelled','completed') AND due_datetime<?",[$site,$id,$finish]);$points=[];
            foreach($busy as $d){$until=(new DateTimeImmutable($d['due_datetime']))->modify('+'.max(5,(int)($d['duration_min']?:20)).' minutes')->format('Y-m-d H:i:s');if($until<=$due)continue;if((int)$d['gate_id']===$gate){$points[]=[max($due,$d['due_datetime']),1];$points[]=[min($finish,$until),-1];}if($resource&&(int)$d['resource_id']===$resource)self::error('That crane or forklift is already reserved during this delivery.',409);}
            usort($points,static fn($a,$b)=>strcmp($a[0],$b[0])?:$a[1]<=>$b[1]);$used=0;foreach($points as $point){$used+=$point[1];if($used>=(int)$g['capacity'])self::error('That gate is full during this delivery.',409);}
            try{$originalSite=(int)$this->db->query('SELECT MIN(id) FROM logistics_sites')->fetchColumn();$blackouts=$site===$originalSite?$this->rows('SELECT * FROM blackouts WHERE date=?',[$date->format('Y-m-d')]):[];}catch(PDOException){$blackouts=[];}
            foreach($blackouts as $blackout){$bs=empty($blackout['start'])?$start:$minutes($blackout['start']);$be=empty($blackout['end'])?$end:$minutes($blackout['end']);if($at<$be&&$at+$duration>$bs)self::error('This time is closed: '.($blackout['reason']?:'site blackout'),409);}
            $args=[$company?:null,$gate,$resource?:null,$due,$duration,$method,$fields['supplier'],$fields['material'],$fields['quantity'],$fields['user_name']];
            if($old){$this->run('UPDATE deliveries SET company_id=?,gate_id=?,resource_id=?,due_datetime=?,duration_min=?,unloading_method=?,supplier=?,material=?,quantity=?,user_name=?,logistics_revision=logistics_revision+1 WHERE id=?',array_merge($args,[$id]));}
            else{$this->run("INSERT INTO deliveries(company_id,gate_id,resource_id,due_datetime,duration_min,unloading_method,supplier,material,quantity,user_name,site_id,status,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,'Booked in',?)",array_merge($args,[$site,date('Y-m-d H:i:s')]));$id=(int)$this->db->lastInsertId();}
            $this->audit($u,$site,$old?'rescheduled_or_edited':'booked',$id,['gate_id'=>$gate,'resource_id'=>$resource,'due'=>$due]);$this->db->commit();return $this->delivery($u,$id);
        }catch(Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public function cancel(array $u,int $id,int $revision): void {
        $d=$this->delivery($u,$id);if(strtolower($d['status'])==='completed')self::error('Completed deliveries cannot be cancelled.',409);
        $this->db->beginTransaction();try{$s=$this->db->prepare("UPDATE deliveries SET status='Cancelled',logistics_revision=logistics_revision+1 WHERE id=? AND logistics_revision=? AND LOWER(status)<>'completed'");$s->execute([$id,$revision]);if($s->rowCount()!==1)self::error('Booking changed. Refresh and retry.',409);$this->audit($u,(int)$d['site_id'],'cancelled',$id);$this->db->commit();}catch(Throwable $e){$this->db->rollBack();throw $e;}
    }
}
