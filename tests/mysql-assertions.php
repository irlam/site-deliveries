<?php
declare(strict_types=1);
require dirname(__DIR__).'/includes/logistics.php';
$pdo=new PDO('mysql:host=127.0.0.1;port=3308;dbname=deliveries_gate_fixture;charset=utf8mb4','root','');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$l=new Logistics($pdo);
if(in_array('--worker',$argv,true)){
    try{$l->save(['id'=>1,'name'=>'Admin','admin'=>true,'company_id'=>null],['site_id'=>1,'company_id'=>1,'gate_id'=>2,'resource_id'=>2,'due_datetime'=>'2026-10-20 09:00','duration_min'=>60,'supplier'=>'Electrical','material'=>'Concurrent load','quantity'=>'1','user_name'=>'Admin']);echo 'created';}catch(RuntimeException $e){if($e->getCode()===409){echo 'conflict';exit;}throw $e;}exit;
}
if($l->one('SELECT material,due_datetime FROM deliveries WHERE id=1')!==['material'=>'Existing booking','due_datetime'=>'2026-10-12 08:00:00'])throw new RuntimeException('Historical booking changed.');
if((int)$l->one('SELECT site_id FROM deliveries WHERE id=1')['site_id']!==1)throw new RuntimeException('Historical site mapping failed.');
foreach($l->rows('SHOW INDEX FROM deliveries')as $i)if($i['Key_name']==='uniq_slot_global')throw new RuntimeException('Global unique slot not removed.');
$pdo->exec("INSERT INTO logistics_companies(id,name) VALUES(2,'Acme Electrical'),(3,'Bravo Drylining');INSERT INTO logistics_gates(id,site_id,name,capacity) VALUES(2,1,'South gate',1),(3,1,'East gate',1);INSERT INTO logistics_resources(id,site_id,name,kind) VALUES(1,1,'Crane 1','Crane'),(2,1,'Crane 2','Crane'),(3,1,'Forklift 1','Forklift');INSERT INTO logistics_gate_resources VALUES(1,1),(2,2),(3,3);");
$s=$pdo->prepare('INSERT INTO logistics_users(id,company_id,name,email,password_hash) VALUES(?,?,?,?,?)');foreach([[1,2,'Electrician','electrical@example.invalid'],[2,3,'Dryliner','drylining@example.invalid']]as $user)$s->execute(array_merge($user,[password_hash('Disposable-deliveries-test-only!',PASSWORD_DEFAULT)]));$pdo->exec('INSERT INTO logistics_memberships VALUES(1,1),(2,1)');
$a=['id'=>1,'name'=>'Admin','admin'=>true,'company_id'=>null];$base=['site_id'=>1,'company_id'=>2,'gate_id'=>1,'resource_id'=>1,'due_datetime'=>'2026-10-12 09:00','duration_min'=>60,'supplier'=>'Acme Electrical','material'=>'Cable drums','quantity'=>'1 pallet','user_name'=>'Electrician'];
$l->save($a,$base);$l->save($a,array_replace($base,['company_id'=>3,'gate_id'=>2,'resource_id'=>2,'supplier'=>'Bravo Drylining','material'=>'Plasterboard']));$l->save($a,array_replace($base,['company_id'=>3,'gate_id'=>3,'resource_id'=>3,'supplier'=>'Bravo Drylining','material'=>'Flooring']));
echo "PASS: MySQL upgrade is repeatable, retains historical bookings and permits three simultaneous gates.\n";
