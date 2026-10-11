<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/db.php';
$pdo->exec('CREATE TABLE IF NOT EXISTS logistics_suite_users (
 instance_id BIGINT UNSIGNED NOT NULL,
 suite_user_id BIGINT UNSIGNED NOT NULL,
 local_user_id INT NOT NULL,
 PRIMARY KEY(instance_id,suite_user_id),
 UNIQUE KEY suite_local_user(local_user_id)
) ENGINE=InnoDB');
echo "Suite delivery identity mapping is installed.\n";
