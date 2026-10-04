<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/config.push.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

function push_notify_one(array $subscription, array $payload): bool {
    $webPush = new WebPush([
        'VAPID' => [
            'subject'    => PUSH_SUBJECT,
            'publicKey'  => PUSH_VAPID_PUBLIC,
            'privateKey' => PUSH_VAPID_PRIVATE,
        ],
    ]);

    $sub = Subscription::create([
        'endpoint'  => $subscription['endpoint'] ?? '',
        'publicKey' => $subscription['keys']['p256dh'] ?? '',
        'authToken' => $subscription['keys']['auth'] ?? '',
    ]);

    $report = $webPush->sendOneNotification($sub, json_encode($payload, JSON_UNESCAPED_SLASHES));
    return $report->isSuccess();
}
