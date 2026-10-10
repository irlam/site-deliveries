<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/logistics.php';
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$l=new Logistics($db);$checks=0;
function check(bool $ok,string $label): void{global $checks;if(!$ok)throw new RuntimeException('FAIL: '.$label);$checks++;echo "PASS: $label\n";}
function denied(callable $f,int $code,string $label): void{try{$f();throw new RuntimeException('Not denied');}catch(RuntimeException $e){check($e->getCode()===$code,$label);}}
$db->exec("CREATE TABLE logistics_sites(id INTEGER PRIMARY KEY,name TEXT,operator_name TEXT,active INTEGER);CREATE TABLE logistics_companies(id INTEGER PRIMARY KEY,name TEXT,active INTEGER);CREATE TABLE logistics_gates(id INTEGER PRIMARY KEY,site_id INTEGER,name TEXT,capacity INTEGER,active INTEGER);CREATE TABLE logistics_resources(id INTEGER PRIMARY KEY,site_id INTEGER,name TEXT,kind TEXT,active INTEGER);CREATE TABLE logistics_gate_resources(gate_id INTEGER,resource_id INTEGER);CREATE TABLE logistics_memberships(user_id INTEGER,site_id INTEGER);CREATE TABLE logistics_audit(id INTEGER PRIMARY KEY,site_id INTEGER,actor TEXT,event TEXT,delivery_id INTEGER,details TEXT,created_at TEXT);CREATE TABLE blackouts(id INTEGER PRIMARY KEY,date TEXT,start TEXT,end TEXT,reason TEXT);CREATE TABLE deliveries(id INTEGER PRIMARY KEY,site_id INTEGER,company_id INTEGER,gate_id INTEGER,resource_id INTEGER,due_datetime TEXT,duration_min INTEGER,unloading_method TEXT,supplier TEXT,material TEXT,quantity TEXT,user_name TEXT,status TEXT,created_at TEXT,logistics_revision INTEGER DEFAULT 1);
INSERT INTO logistics_sites VALUES(1,'Rochdale Road','McGoff Construction',1),(2,'Other site','Other operator',1);INSERT INTO logistics_companies VALUES(1,'Electrical',1),(2,'Drylining',1);INSERT INTO logistics_gates VALUES(1,1,'North',1,1),(2,1,'South',1,1),(3,1,'East',2,1),(4,2,'Other gate',1,1);INSERT INTO logistics_resources VALUES(1,1,'Crane 1','Crane',1),(2,1,'Crane 2','Crane',1),(3,1,'Forklift 1','Forklift',1);INSERT INTO logistics_gate_resources VALUES(1,1),(2,2),(2,1),(3,3);INSERT INTO logistics_memberships VALUES(1,1),(2,1);");
$admin=['id'=>1,'name'=>'Admin','admin'=>true,'company_id'=>null];$electric=['id'=>1,'name'=>'Electrician','admin'=>false,'company_id'=>1];$dry=['id'=>2,'name'=>'Dryliner','admin'=>false,'company_id'=>2];
$input=['site_id'=>1,'company_id'=>1,'gate_id'=>1,'resource_id'=>1,'due_datetime'=>'2026-10-12 09:00','duration_min'=>60,'supplier'=>'Electrical','material'=>'Cable','quantity'=>'1 pallet','user_name'=>'Electrician'];
$a=$l->save($electric,$input);$b=$l->save($dry,array_replace($input,['gate_id'=>2,'resource_id'=>2,'material'=>'Board']));
check($a['due_datetime']===$b['due_datetime'],'different gates and cranes accept simultaneous bookings');
check((int)$b['company_id']===2&&$b['supplier']==='Drylining','company cannot spoof booking ownership or contractor');
denied(fn()=>$l->save($electric,$input),409,'same crane/gate overlap denied');
denied(fn()=>$l->save($electric,array_replace($input,['gate_id'=>2,'resource_id'=>1])),409,'shared crane overlap denied across different gates');
denied(fn()=>$l->save($electric,array_replace($input,['due_datetime'=>'2026-10-12 09:40'])),409,'overlapping duration blocked despite different start time');
$adjacent=$l->save($electric,array_replace($input,['due_datetime'=>'2026-10-12 10:00']));check($adjacent['id']>$a['id'],'adjacent bookings are allowed');
denied(fn()=>$l->delivery($electric,(int)$b['id']),403,'other company detail denied');
denied(fn()=>$l->save($electric,['id'=>$b['id'],'revision'=>1,'material'=>'No']),403,'other company edit denied');
denied(fn()=>$l->cancel($electric,(int)$b['id'],1),403,'other company cancellation denied');
denied(fn()=>$l->calendar($electric,2,'2026-10-12','2026-10-19'),403,'other site calendar denied');
denied(fn()=>$l->save($electric,array_replace($input,['site_id'=>2,'gate_id'=>4,'resource_id'=>0,'unloading_method'=>'Manual'])),403,'other site booking denied');
denied(fn()=>$l->save($electric,array_replace($input,['gate_id'=>4])),422,'gate must belong to booking site');
denied(fn()=>$l->save($electric,array_replace($input,['gate_id'=>3])),422,'resource must be assigned to selected gate');
$calendar=$l->calendar($electric,1,'2026-10-12','2026-10-19');$private=array_values(array_filter($calendar,fn($d)=>$d['private']))[0];
check(!isset($private['id'],$private['company_id'],$private['supplier'],$private['user_name'])&&$private['material']==='Unavailable','other company availability contains no private booking fields');
check(count($l->calendar($admin,1,'2026-10-12','2026-10-19'))===3,'admin retains site-wide overview');
denied(fn()=>$l->save($electric,['id'=>$a['id'],'revision'=>0,'due_datetime'=>'2026-10-12 11:00']),409,'stale drag/edit revision denied');
$moved=$l->save($admin,['id'=>$a['id'],'revision'=>1,'due_datetime'=>'2026-10-12 11:00','gate_id'=>2,'resource_id'=>2]);check((int)$moved['gate_id']===2&&(int)$moved['logistics_revision']===2,'drag between gates updates reservation and revision');
$l->cancel($electric,(int)$adjacent['id'],1);check(strtolower($l->delivery($electric,(int)$adjacent['id'])['status'])==='cancelled','cancellation retains booking record');
$l->save($electric,array_replace($input,['due_datetime'=>'2026-10-12 10:00']));check(true,'cancelled booking releases slot');
$db->exec("INSERT INTO blackouts VALUES(1,'2026-10-13','09:00','10:00','Closure')");denied(fn()=>$l->save($electric,array_replace($input,['due_datetime'=>'2026-10-13 08:40'])),409,'blackout overlap preserved');
denied(fn()=>$l->save($electric,array_replace($input,['due_datetime'=>'2026-02-30 09:00'])),422,'invalid dates rejected');
denied(fn()=>$l->save($electric,array_replace($input,['due_datetime'=>'2026-10-12 17:40'])),422,'duration cannot extend outside operating hours');
denied(fn()=>$l->save($electric,array_replace($input,['due_datetime'=>'2026-10-12 11:10'])),422,'admin slot interval preserved');
$manual=array_replace($input,['gate_id'=>3,'resource_id'=>0,'unloading_method'=>'Manual']);$c=$l->save($electric,array_replace($manual,['due_datetime'=>'2026-10-14 09:00','duration_min'=>20]));$d=$l->save($electric,array_replace($manual,['due_datetime'=>'2026-10-14 09:20','duration_min'=>20]));$l->save($electric,array_replace($manual,['due_datetime'=>'2026-10-14 09:00','duration_min'=>40]));check(true,'capacity counts peak concurrent occupancy rather than all intersecting bookings');
denied(fn()=>$l->save($electric,array_replace($manual,['due_datetime'=>'2026-10-14 09:00','duration_min'=>20])),409,'gate capacity limit enforced');
$db->exec('DELETE FROM logistics_memberships WHERE user_id=1');denied(fn()=>$l->delivery($electric,(int)$a['id']),403,'membership removal immediately revokes record access');
check(count($l->rows('SELECT * FROM logistics_audit'))>=8,'bookings edits and cancellations keep audit history');
$other=$l->save($admin,array_replace($input,['site_id'=>2,'gate_id'=>4,'resource_id'=>0,'unloading_method'=>'Manual','due_datetime'=>'2026-10-13 09:00']));check((int)$other['site_id']===2,'original site blackout does not close another site');

echo "$checks logistics checks passed.\n";
