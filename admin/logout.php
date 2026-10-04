<?php
// /admin/logout.php
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';

// Optional: enforce HTTPS
// admin_force_https();

admin_logout();
header('Location: /admin/login.php');
exit;
