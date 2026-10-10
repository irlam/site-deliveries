<?php
declare(strict_types=1);
require __DIR__.'/includes/logistics-auth.php';
try{logistics_write_check();unset($_SESSION['logistics_user_id']);session_regenerate_id(true);header('Location: /company-login.php');}catch(Throwable){http_response_code(403);echo 'Refresh and retry.';}
