<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/logistics.php';
require dirname(__DIR__).'/includes/delivery-suite.php';
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE logistics_sites(id INTEGER PRIMARY KEY,active INTEGER); CREATE TABLE logistics_companies(id INTEGER PRIMARY KEY,active INTEGER); CREATE TABLE logistics_users(id INTEGER PRIMARY KEY,company_id INTEGER,name TEXT,email TEXT UNIQUE,password_hash TEXT,active INTEGER,role TEXT); CREATE TABLE logistics_memberships(user_id INTEGER,site_id INTEGER); CREATE TABLE logistics_suite_users(instance_id INTEGER,suite_user_id INTEGER,local_user_id INTEGER UNIQUE,PRIMARY KEY(instance_id,suite_user_id)); INSERT INTO logistics_sites VALUES(1,1),(2,1); INSERT INTO logistics_companies VALUES(1,1),(2,1);");
$binding=['instance_id'=>5,'organization_id'=>7,'project_id'=>7,'local_site_id'=>1,'local_company_id'=>1,'key'=>str_repeat('a',64)];
$identity=$binding+['module_key'=>'deliveries','user_id'=>8,'session_expires_at'=>time()+300,'role'=>'manager','name'=>'Test manager'];
$checks=0;
function check(bool $ok):void{global $checks;if(!$ok)throw new RuntimeException('Assertion failed');$checks++;}
function denied(callable $call,int $status):void{try{$call();}catch(RuntimeException $e){check($e->getCode()===$status);return;}throw new RuntimeException('Access unexpectedly allowed');}
check(DeliverySuite::identity($binding,['identity'=>$identity])['user_id']===8);
denied(fn()=>DeliverySuite::identity($binding,['identity'=>array_replace($identity,['organization_id'=>8])]),403);
denied(fn()=>DeliverySuite::identity($binding,['identity'=>array_replace($identity,['session_expires_at'=>time()-1])]),401);
$user=DeliverySuite::user($db,$binding,$identity,true);
check(!$user['admin']&&$user['company_id']===1&&$user['suite_site_id']===1&&$user['role']==='booker');
check(DeliverySuite::user($db,$binding,array_replace($identity,['role'=>'platform_admin']))['admin']===false);
check(DeliverySuite::user($db,$binding,array_replace($identity,['role'=>'viewer']))['role']==='viewer');
$db->exec("UPDATE logistics_users SET role='viewer'");
check(DeliverySuite::user($db,$binding,$identity)['role']==='viewer');
denied(fn()=>DeliverySuite::user($db,array_replace($binding,['local_company_id'=>2]),$identity),403);
$db->exec('DELETE FROM logistics_memberships');
denied(fn()=>DeliverySuite::user($db,$binding,$identity),403);
$db->exec('INSERT INTO logistics_memberships VALUES(1,1); UPDATE logistics_users SET active=0');
denied(fn()=>DeliverySuite::user($db,$binding,$identity),403);
denied(fn()=>DeliverySuite::request($binding,'module-session',[],fn()=>['ok'=>false]),401);
echo "$checks Suite company access checks passed.\n";
