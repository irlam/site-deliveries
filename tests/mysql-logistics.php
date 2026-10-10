<?php
declare(strict_types=1);
// Uses only a named disposable database on loopback. Never reads production db.php.
$dsn=getenv('DELIVERIES_TEST_DSN')?:'mysql:host=127.0.0.1;port=3308;charset=utf8mb4';
if(!str_contains($dsn,'host=127.0.0.1'))throw new RuntimeException('Disposable tests require loopback.');
$pdo=new PDO($dsn,getenv('DELIVERIES_TEST_USER')?:'root',getenv('DELIVERIES_TEST_PASSWORD')?:'');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE DATABASE IF NOT EXISTS deliveries_gate_fixture CHARACTER SET utf8mb4');$pdo->exec('USE deliveries_gate_fixture');
foreach($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN)as $table)$pdo->exec('DROP TABLE `'.str_replace('`','``',$table).'`');
$pdo->exec("CREATE TABLE deliveries(id INT AUTO_INCREMENT PRIMARY KEY,supplier VARCHAR(180),material VARCHAR(180),quantity VARCHAR(180),user_name VARCHAR(180),driver VARCHAR(180),vehicle VARCHAR(180),due_datetime DATETIME,status VARCHAR(80),unloading_method VARCHAR(120),duration_min INT DEFAULT 20,created_at DATETIME DEFAULT CURRENT_TIMESTAMP,arrived_at DATETIME NULL,completed_at DATETIME NULL,no_show TINYINT DEFAULT 0,slot_key VARCHAR(16) GENERATED ALWAYS AS (YEAR(due_datetime)*100000000+MONTH(due_datetime)*1000000+DAY(due_datetime)*10000+HOUR(due_datetime)*100+MINUTE(due_datetime)) STORED,UNIQUE KEY uniq_slot_global(slot_key)) ENGINE=InnoDB;
CREATE TABLE admins(id INT AUTO_INCREMENT PRIMARY KEY,email VARCHAR(254),password_hash VARCHAR(255),role VARCHAR(30),is_active TINYINT,created_at DATETIME,last_login DATETIME);
CREATE TABLE blackouts(id INT AUTO_INCREMENT PRIMARY KEY,date DATE,start TIME,end TIME,reason VARCHAR(255));
CREATE TABLE delivery_files(id INT AUTO_INCREMENT PRIMARY KEY,delivery_id INT,path VARCHAR(255),label VARCHAR(255),created_at DATETIME DEFAULT CURRENT_TIMESTAMP);
CREATE TABLE delivery_change_requests(id INT AUTO_INCREMENT PRIMARY KEY,delivery_id INT,requester_name VARCHAR(180),contact VARCHAR(180),request_type VARCHAR(40),requested_dt DATETIME NULL,details TEXT,status VARCHAR(30));
INSERT INTO deliveries(supplier,material,quantity,user_name,due_datetime,status,unloading_method) VALUES('Historical Contractor','Existing booking','1','Original user','2026-10-12 08:00','Booked in','Manual');");
$insert=$pdo->prepare("INSERT INTO admins(email,password_hash,role,is_active) VALUES(?,?,'owner',1)");$insert->execute(['admin@example.invalid',password_hash('Disposable-deliveries-test-only!',PASSWORD_DEFAULT)]);
echo "Disposable MySQL fixture ready. No production database accessed.\n";
