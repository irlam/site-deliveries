<?php
// /includes/push_helpers.php
// Small, framework-free helpers reused by admin push tools.

declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/vapid.php';

/**
 * Return total number of subscribed devices, unioning both possible tables.
 */
function push_total_subscribers(PDO $pdo): int {
    $sql = "
        SELECT COALESCE(SUM(cnt),0) AS total FROM (
            SELECT COUNT(*) AS cnt FROM push_subscriptions
            UNION ALL
            SELECT COUNT(*) AS cnt FROM webpush_subscriptions
        ) t
    ";
    try {
        return (int)$pdo->query($sql)->fetchColumn();
    } catch (Throwable $e) {
        // fallbacks if one/both tables are missing
        foreach (['push_subscriptions','webpush_subscriptions'] as $t) {
            try {
                return (int)$pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
            } catch (Throwable $e2) { /* ignore */ }
        }
        return 0;
    }
}

/**
 * Compute the correct audience (aud) for a web-push endpoint.
 * Example: https://fcm.googleapis.com/fcm/send/... -> https://fcm.googleapis.com
 */
function push_origin_for_aud(string $endpoint): string {
    $p = parse_url($endpoint);
    $scheme = $p['scheme'] ?? 'https';
    $host   = $p['host']   ?? '';
    return $scheme . '://' . $host;
}

/**
 * Base64url helpers.
 */
function b64u_encode(string $bin): string {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}
function b64u_decode(string $b64url): string {
    $b64 = strtr($b64url, '-_', '+/');
    return base64_decode($b64 . str_repeat('=', (4 - strlen($b64) % 4) % 4));
}

/**
 * Attach best-effort error details from a cURL handle.
 */
function push_collect_response_detail($ch, string $body): string {
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $trim = trim($body ?? '');
    if ($trim === '') return 'HTTP '.$code;
    // FCM often returns small JSON; keep it short
    if (strlen($trim) > 400) $trim = substr($trim, 0, 397).'...';
    return $trim . ' (HTTP '.$code.')';
}
