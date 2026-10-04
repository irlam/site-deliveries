<?php
// /includes/push_send.php
declare(strict_types=1);

require_once __DIR__ . '/vapid.php'; // defines VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY

/**
 * Sends a web push to every subscription in push_subscriptions.
 * Automatically deletes endpoints that return 404/410.
 *
 * @param PDO   $pdo
 * @param array $payload   keys: title, body, url?, icon?, badge?, tag?, requireInteraction?
 * @param string $urgency  'very-low'|'low'|'normal'|'high'
 * @param int $ttlSeconds
 * @return array ['sent'=>int,'failed'=>int]
 * @throws RuntimeException
 */
function push_broadcast_all(PDO $pdo, array $payload, string $urgency = 'normal', int $ttlSeconds = 1800): array
{
    // Try to include the library (prebuilt vendor-lt or standard vendor)
    $autoloadOk = false;
    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../vendor-lt/autoload.php'] as $auto) {
        if (is_file($auto)) { require_once $auto; $autoloadOk = true; break; }
    }
    if (!$autoloadOk) {
        throw new RuntimeException('Web Push library not found. Place a prebuilt Minishlink/WebPush vendor folder at /vendor or /vendor-lt.');
    }

    // Validate keys
    if (!defined('VAPID_PUBLIC_KEY') || !defined('VAPID_PRIVATE_KEY') || VAPID_PUBLIC_KEY === '' || VAPID_PRIVATE_KEY === '') {
        throw new RuntimeException('VAPID keys are missing in includes/vapid.php');
    }

    // Fetch subscriptions
    $stmt = $pdo->query("SELECT id, endpoint, p256dh, auth FROM push_subscriptions ORDER BY id ASC");
    $subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$subs) return ['sent'=>0,'failed'=>0];

    // Build options and payload
    $options = [
        'TTL'      => $ttlSeconds,
        'urgency'  => $urgency,
        'topic'    => $payload['tag'] ?? 'broadcast'
    ];
    $serverKeys = [
        'VAPID' => [
            'subject' => (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : 'https://example.com'),
            'publicKey' => VAPID_PUBLIC_KEY,
            'privateKey' => VAPID_PRIVATE_KEY,
        ]
    ];

    $webPush = new \Minishlink\WebPush\WebPush($serverKeys, [
        'timeout' => 15,
        'reuseVAPIDHeaders' => true,
        'automaticPadding' => true,
    ]);

    $payloadJson = json_encode([
        'title' => (string)($payload['title'] ?? ''),
        'body'  => (string)($payload['body'] ?? ''),
        'url'   => (string)($payload['url'] ?? ''),
        'icon'  => (string)($payload['icon'] ?? ''),
        'badge' => (string)($payload['badge'] ?? ''),
        'tag'   => (string)($payload['tag'] ?? 'broadcast'),
        'requireInteraction' => (bool)($payload['requireInteraction'] ?? false),
    ], JSON_UNESCAPED_SLASHES);

    $sent = 0; $failed = 0; $toDelete = [];

    foreach ($subs as $s) {
        $sub = \Minishlink\WebPush\Subscription::create([
            'endpoint' => $s['endpoint'],
            'keys' => ['p256dh' => $s['p256dh'], 'auth' => $s['auth']],
        ]);
        $webPush->queueNotification($sub, $payloadJson, $options);
    }

    foreach ($webPush->flush() as $report) {
        if ($report->isSuccess()) {
            $sent++;
        } else {
            $failed++;
            $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
            if (in_array($status, [404, 410], true)) {
                $endpoint = $report->getRequest()->getUri()->__toString();
                $toDelete[] = $endpoint;
            }
        }
    }

    // Clean-up dead endpoints
    if ($toDelete) {
        $in = implode(',', array_fill(0, count($toDelete), '?'));
        $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE endpoint IN ($in)");
        $del->execute($toDelete);
    }

    return ['sent'=>$sent, 'failed'=>$failed];
}
