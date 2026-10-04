<?php
// /api/vapid_public.php
declare(strict_types=1);
header('Content-Type: text/plain; charset=UTF-8');

// Read from env or a config file (prefer env)
$pub = getenv('VAPID_PUBLIC') ?: '';
if ($pub === '') {
  http_response_code(500);
  echo "Missing VAPID_PUBLIC";
  exit;
}
echo trim($pub);
