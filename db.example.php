<?php
$host = 'localhost';
$db = 'your_database';
$user = 'your_database_user';
$pass = 'configure_locally';
$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
