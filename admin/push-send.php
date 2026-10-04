<?php
// /admin/push-send.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
require_once __DIR__ . '/../includes/settings.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

try {
    require_once __DIR__ . '/../vendor/autoload.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Composer autoload missing','first_error'=>$e->getMessage()]);
    exit;
}

admin_require($pdo);

// ----------------------------------------------------------
// tiny helpers
// ----------------------------------------------------------
if (!function_exists('h')) {
    function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * Return VAPID keys or throw if missing.
 */
function get_vapid_keys(): array {
    $vapidPath = __DIR__ . '/../includes/vapid.php';
    if (!is_file($vapidPath)) {
        throw new RuntimeException('VAPID key file missing (vapid.php)');
    }
    require $vapidPath;
    if (!defined('VAPID_PUBLIC_KEY') || !defined('VAPID_PRIVATE_KEY')) {
        throw new RuntimeException('VAPID constants not defined');
    }
    return [VAPID_PUBLIC_KEY, VAPID_PRIVATE_KEY];
}

/**
 * Best-effort SELECT from a table, returning rows with stable keys:
 * endpoint, p256dh, auth, channel (nullable), created_at (nullable), updated_at (nullable), _source ('push'|'webpush'), _id
 */
function fetch_subs_from(PDO $pdo, string $table, string $source): array {
    $rows = [];
    // attempt a "rich" select; fall back progressively
    $sqls = [
        "SELECT id AS _id, endpoint, p256dh, auth,
                COALESCE(channel, NULL) AS channel,
                COALESCE(updated_at, NULL) AS updated_at,
                COALESCE(created_at, NULL) AS created_at
         FROM {$table}",
        "SELECT id AS _id, endpoint, p256dh, auth FROM {$table}",
    ];
    foreach ($sqls as $sql) {
        try {
            $q = $pdo->query($sql);
            $out = $q ? $q->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($out as &$r) {
                $r += ['channel'=>null,'updated_at'=>null,'created_at'=>null];
                $r['_source'] = $source;
            }
            return $out;
        } catch (Throwable $e) { /* try next */ }
    }
    return $rows;
}

/**
 * Union subs from both possible tables; supports:
 *  - $endpoint: exact endpoint match (takes precedence)
 *  - $tag: match channel/tag (string compare, case-sensitive)
 *  - $newestOnly: return a single newest (by updated_at/created_at/_id)
 */
function safe_union_subs(PDO $pdo, ?string $tag, ?string $endpoint, bool $newestOnly=false): array {
    $all = [];

    // push_subscriptions
    try { $all = array_merge($all, fetch_subs_from($pdo, 'push_subscriptions', 'push')); } catch (Throwable $e) {}
    // webpush_subscriptions
    try { $all = array_merge($all, fetch_subs_from($pdo, 'webpush_subscriptions', 'webpush')); } catch (Throwable $e) {}

    // Filters
    if ($endpoint) {
        $all = array_values(array_filter($all, fn($r) => (string)$r['endpoint'] === (string)$endpoint));
    } elseif ($tag) {
        $all = array_values(array_filter($all, fn($r) => isset($r['channel']) && (string)$r['channel'] === (string)$tag));
    }

    // Sort newest first
    usort($all, function($a,$b){
        $ka = $a['updated_at'] ?: $a['created_at'] ?: null;
        $kb = $b['updated_at'] ?: $b['created_at'] ?: null;
        if ($ka && $kb) {
            return strcmp($kb, $ka); // desc
        }
        if ($ka && !$kb) return -1;
        if (!$ka && $kb) return 1;
        // tie-breaker by id desc
        return ((int)($b['_id'] ?? 0)) <=> ((int)($a['_id'] ?? 0));
    });

    if ($newestOnly) {
        return $all ? [ $all[0] ] : [];
    }
    return $all;
}

/**
 * Self-heal push_broadcasts structure (ensure table and notes column).
 */
function ensure_broadcasts_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS push_broadcasts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            url TEXT NULL,
            icon TEXT NULL,
            badge TEXT NULL,
            tag VARCHAR(128) NULL,
            urgency VARCHAR(16) NOT NULL DEFAULT 'normal',
            require_interaction TINYINT(1) NOT NULL DEFAULT 0,
            sent INT NOT NULL DEFAULT 0,
            failed INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    try { $pdo->query("SELECT notes FROM push_broadcasts LIMIT 0"); }
    catch (Throwable $e) { $pdo->exec("ALTER TABLE push_broadcasts ADD COLUMN notes TEXT NULL AFTER body"); }
}

/**
 * Delete a subscription by endpoint from either table.
 */
function delete_by_endpoint(PDO $pdo, string $endpoint): void {
    foreach (['push_subscriptions','webpush_subscriptions'] as $t) {
        try {
            $stmt = $pdo->prepare("DELETE FROM {$t} WHERE endpoint = :e");
            $stmt->execute([':e'=>$endpoint]);
        } catch (Throwable $e) {}
    }
}

// ----------------------------------------------------------
// read input
// ----------------------------------------------------------
$title  = trim((string)($_POST['title'] ?? ''));
$body   = trim((string)($_POST['body'] ?? ''));
$url    = trim((string)($_POST['url'] ?? ''));
$icon   = trim((string)($_POST['icon'] ?? ''));
$badge  = trim((string)($_POST['badge'] ?? ''));
$tag    = trim((string)($_POST['tag'] ?? ''));
$urg    = (string)($_POST['urgency'] ?? 'normal');
$requireInteraction = isset($_POST['require_interaction']) && (string)$_POST['require_interaction'] === '1';
$endpoint = trim((string)($_POST['endpoint'] ?? ''));
$notes    = trim((string)($_POST['notes'] ?? ''));
$dryRun   = isset($_POST['dry_run']) && (string)$_POST['dry_run'] === '1';
$testNewest = isset($_POST['test_newest']) && (string)$_POST['test_newest'] === '1';

if ($title === '' || $body === '') {
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'title and body required']);
    exit;
}

// ----------------------------------------------------------
// gather targets
// ----------------------------------------------------------
try {
    $targets = safe_union_subs($pdo, $tag !== '' ? $tag : null, $endpoint !== '' ? $endpoint : null, $testNewest);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Unable to enumerate subscriptions','first_error'=>$e->getMessage()]);
    exit;
}

$count = count($targets);
if ($dryRun) {
    echo json_encode(['ok'=>true,'dry_run'=>true,'count'=>$count]);
    exit;
}
if ($count === 0) {
    echo json_encode(['ok'=>true,'sent'=>0,'failed'=>0,'note'=>'No matching subscriptions']);
    exit;
}

// ----------------------------------------------------------
// set up WebPush
// ----------------------------------------------------------
try {
    [ $VAPID_PUBLIC, $VAPID_PRIVATE ] = get_vapid_keys();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
    exit;
}

$auth = [
    'VAPID' => [
        'subject' => (isset($_SERVER['HTTP_HOST']) ? ('https://' . $_SERVER['HTTP_HOST'] . '/') : 'mailto:admin@localhost'),
        'publicKey' => $VAPID_PUBLIC,
        'privateKey' => $VAPID_PRIVATE,
    ],
];
$webPush = new WebPush($auth);

// optional default payload fields
$payload = [
    'title' => $title,
    'body'  => $body,
];
if ($icon  !== '') $payload['icon']  = $icon;
if ($badge !== '') $payload['badge'] = $badge;
if ($url   !== '') $payload['data']  = ['url'=>$url];

$sent = 0; $failed = 0;
$firstError = null;

foreach ($targets as $t) {
    try {
        $sub = Subscription::create([
            'endpoint' => $t['endpoint'],
            'publicKey' => $t['p256dh'],
            'authToken' => $t['auth'],
            'contentEncoding' => 'aes128gcm', // let lib auto-detect as well
        ]);

        $opts = [
            'TTL' => 60,
            'urgency' => $urg,
            'topic' => $tag ?: null,
            'requireInteraction' => $requireInteraction ? 1 : 0,
        ];
        // remove nulls
        $opts = array_filter($opts, fn($v)=>$v!==null);

        $report = $webPush->sendOneNotification($sub, json_encode($payload), $opts);
        if ($report->isSuccess()) {
            $sent++;
        } else {
            $failed++;
            $reason = $report->getReason();
            if ($firstError === null) $firstError = $reason ?: 'send failed';
            // clean dead endpoints
            $status = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
            if (in_array($status, [404,410], true)) {
                delete_by_endpoint($pdo, $t['endpoint']);
            }
        }
    } catch (Throwable $e) {
        $failed++;
        if ($firstError === null) $firstError = $e->getMessage();
    }
}

// ----------------------------------------------------------
// log to history
// ----------------------------------------------------------
try {
    ensure_broadcasts_table($pdo);
    $stmt = $pdo->prepare("
        INSERT INTO push_broadcasts
        (title, body, url, icon, badge, tag, urgency, require_interaction, sent, failed, notes)
        VALUES (:title,:body,:url,:icon,:badge,:tag,:urgency,:ri,:sent,:failed,:notes)
    ");
    $stmt->execute([
        ':title'=>$title, ':body'=>$body, ':url'=>($url!==''?$url:null),
        ':icon'=>($icon!==''?$icon:null), ':badge'=>($badge!==''?$badge:null),
        ':tag'=>($tag!==''?$tag:null), ':urgency'=>$urg,
        ':ri'=>$requireInteraction?1:0, ':sent'=>$sent, ':failed'=>$failed,
        ':notes'=>($notes!==''?$notes:null),
    ]);
} catch (Throwable $e) {
    // non-fatal
}

echo json_encode(['ok'=>true,'sent'=>$sent,'failed'=>$failed,'first_error'=>$firstError]);
