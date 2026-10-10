<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/db.php';
require dirname(__DIR__).'/includes/settings.php';
ensure_app_settings_table($pdo);
$definitions=[
'logistics_sites'=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL,operator_name VARCHAR(180) NOT NULL,active TINYINT NOT NULL DEFAULT 1,suite_project_ref VARCHAR(80) NULL",
'logistics_companies'=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180) NOT NULL UNIQUE,active TINYINT NOT NULL DEFAULT 1",
'logistics_gates'=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,site_id INT UNSIGNED NOT NULL,name VARCHAR(180) NOT NULL,capacity INT NOT NULL DEFAULT 1,active TINYINT NOT NULL DEFAULT 1,UNIQUE KEY(site_id,name)",
'logistics_resources'=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,site_id INT UNSIGNED NOT NULL,name VARCHAR(180) NOT NULL,kind VARCHAR(30) NOT NULL,active TINYINT NOT NULL DEFAULT 1,UNIQUE KEY(site_id,name)",
'logistics_gate_resources'=>"gate_id INT UNSIGNED NOT NULL,resource_id INT UNSIGNED NOT NULL,PRIMARY KEY(gate_id,resource_id)",
'logistics_users'=>"id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,company_id INT UNSIGNED NOT NULL,name VARCHAR(180) NOT NULL,email VARCHAR(254) NOT NULL UNIQUE,password_hash VARCHAR(255) NOT NULL,active TINYINT NOT NULL DEFAULT 1",
'logistics_memberships'=>"user_id INT UNSIGNED NOT NULL,site_id INT UNSIGNED NOT NULL,PRIMARY KEY(user_id,site_id)",
'logistics_audit'=>"id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,site_id INT UNSIGNED NOT NULL,actor VARCHAR(80) NOT NULL,event VARCHAR(80) NOT NULL,delivery_id INT NULL,details TEXT NOT NULL,created_at DATETIME NOT NULL",
];
foreach($definitions as $table=>$cols)$pdo->exec("CREATE TABLE IF NOT EXISTS `$table` ($cols) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$cols=array_column($pdo->query('SHOW COLUMNS FROM deliveries')->fetchAll(PDO::FETCH_ASSOC),'Field');
foreach(['site_id'=>'INT UNSIGNED NULL','company_id'=>'INT UNSIGNED NULL','gate_id'=>'INT UNSIGNED NULL','resource_id'=>'INT UNSIGNED NULL','logistics_revision'=>'INT NOT NULL DEFAULT 1']as $column=>$type)if(!in_array($column,$cols,true))$pdo->exec("ALTER TABLE deliveries ADD COLUMN `$column` $type");
$pdo->exec("INSERT IGNORE INTO logistics_companies(name) VALUES('McGoff Construction')");
if(!(int)$pdo->query('SELECT COUNT(*) FROM logistics_sites')->fetchColumn())$pdo->exec("INSERT INTO logistics_sites(name,operator_name) VALUES('Rochdale Road','McGoff Construction')");
$site=(int)$pdo->query("SELECT id FROM logistics_sites WHERE name='Rochdale Road' ORDER BY id LIMIT 1")->fetchColumn();if(!$site)throw new RuntimeException('Rochdale Road mapping must be reviewed.');
$s=$pdo->prepare("INSERT IGNORE INTO logistics_gates(site_id,name) VALUES(?,'Main gate')");$s->execute([$site]);
$s=$pdo->prepare("SELECT id FROM logistics_gates WHERE site_id=? AND name='Main gate'");$s->execute([$site]);$gate=(int)$s->fetchColumn();
$pdo->beginTransaction();try{$s=$pdo->prepare('UPDATE deliveries SET site_id=?,gate_id=? WHERE site_id IS NULL');$s->execute([$site,$gate]);$pdo->commit();}catch(Throwable $e){$pdo->rollBack();throw $e;}
// Contractor text stays unchanged. Company ownership is deliberately unassigned until admin reviews it.
if(in_array('--activate',$argv,true)){
    $indexes=$pdo->query('SHOW INDEX FROM deliveries')->fetchAll(PDO::FETCH_ASSOC);$group=[];
    foreach($indexes as $i)if(!(int)$i['Non_unique']&&$i['Key_name']!=='PRIMARY')$group[$i['Key_name']][]=$i['Column_name'];
    foreach($group as $name=>$columns)if(count($columns)===1&&in_array($columns[0],['slot_key','due_datetime'],true)){$safe=str_replace('`','``',$name);$pdo->exec("ALTER TABLE deliveries DROP INDEX `$safe`");}
    $s=$pdo->prepare("INSERT INTO app_settings(`key`,value) VALUES('logistics_enabled','1') ON DUPLICATE KEY UPDATE value='1'");$s->execute();
    echo "Gate scheduling enabled. Legacy bookings retain their dates, gate and contractor text.\n";
}else echo "Logistics schema prepared. Calendar remains unchanged until --activate. Review gates/equipment and company ownership in admin.\n";
