<?php
// /admin/push-send.php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405); echo 'Method Not Allowed'; exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/vapid.php';

// ---- Using Minishlink/WebPush (prebuilt vendor uploaded to /vendor) ----
require_once __DIR__ . '/../vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

admin_require($pdo); // must be logged in admin

$title = trim($_POST['title'] ?? '');
$body  = trim($_POST['body'] ?? '');
$url   = trim($_POST['url'] ?? '/');

if ($title === '' || $body === '') {
  http_response_code(400); echo 'Missing title/body'; exit;
}

// store the message (used by /push/pull.php fallback too)
$pdo->exec("
  CREATE TABLE IF NOT EXISTS webpush_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT NOT NULL,
    url VARCHAR(500) DEFAULT '/',
    created_at DATETIME NOT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$ins = $pdo->prepare("INSERT INTO webpush_messages (title, body, url, created_at) VALUES (?, ?, ?, NOW())");
$ins->execute([$title, $body, $url]);

// load subscriptions
$subs = $pdo->query("SELECT endpoint, p256dh, auth FROM webpush_subscriptions")->fetchAll(PDO::FETCH_ASSOC);

$auth = [
  'VAPID' => [
    'subject' => 'mailto:admin@sitedeliveries.site',
    'publicKey' => VAPID_PUBLIC_KEY,
    'privateKey'=> VAPID_PRIVATE_KEY,
  ],
];

$webPush = new WebPush($auth, [
  'TTL' => 60,
]);

$payload = json_encode(['title'=>$title, 'body'=>$body, 'url'=>$url], JSON_UNESCAPED_SLASHES);

$sent=0; $failed=0;
foreach ($subs as $s) {
  $sub = Subscription::create([
    'endpoint' => $s['endpoint'],
    'publicKey'=> $s['p256dh'],
    'authToken'=> $s['auth'],
  ]);
  $report = $webPush->sendOneNotification($sub, $payload);
  if ($report->isSuccess()) $sent++; else $failed++;
}

// flush (not strictly required with sendOneNotification, but harmless)
foreach ($webPush->flush() as $report) { /* drain */ }

header('Content-Type: application/json');
echo json_encode(['sent'=>$sent, 'failed'=>$failed]);
