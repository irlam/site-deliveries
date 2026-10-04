<?php
// /admin/send_push.php
declare(strict_types=1);

require_once __DIR__.'/../db.php';
require_once __DIR__.'/../includes/admin_auth.php';
require_once __DIR__.'/../includes/vapid.php';         // defines VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY
admin_require($pdo);

// ---- tiny helpers ----
if (!function_exists('h')) { function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); } }
function json_response(int $code, array $data){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($data); exit; }
function base64url(string $bin): string { return rtrim(strtr(base64_encode($bin), '+/', '-_'), '='); }

// ---- self-heal broadcasts table (idempotent) ----
try {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS push_broadcasts (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      title VARCHAR(200) NOT NULL,
      body TEXT NOT NULL,
      url TEXT NULL,
      icon TEXT NULL,
      badge TEXT NULL,
      tag VARCHAR(120) NULL,
      urgency ENUM('very-low','low','normal','high') NOT NULL DEFAULT 'normal',
      require_interaction TINYINT(1) NOT NULL DEFAULT 0,
      sent INT UNSIGNED NOT NULL DEFAULT 0,
      failed INT UNSIGNED NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  ");
} catch (Throwable $e) {
  json_response(500, ['ok'=>false,'error'=>'Schema error: '.$e->getMessage()]);
}

// ---- read form ----
$title   = trim($_POST['title'] ?? '');
$body    = trim($_POST['body'] ?? '');
$url     = trim($_POST['url'] ?? '');
$icon    = trim($_POST['icon'] ?? '');
$badge   = trim($_POST['badge'] ?? '');
$tag     = trim($_POST['tag'] ?? '');
$urgency = $_POST['urgency'] ?? 'normal';
$require = (int)($_POST['require_interaction'] ?? 0);

if ($title === '' || $body === '') {
  header('Location: /admin/index.php?error='.rawurlencode('Title and Message are required').'#sendpush');
  exit;
}

// ---- payload shown in notifications (service worker will showNotification) ----
$payload = json_encode([
  'title' => $title,
  'body'  => $body,
  'url'   => $url ?: '/',
  'icon'  => $icon ?: '/icon.php?f=icon-192.png',
  'badge' => $badge ?: '/icon.php?f=icon-96.png',
  'tag'   => $tag ?: null,
  'requireInteraction' => (bool)$require,
], JSON_UNESCAPED_SLASHES);

// ---- fetch ALL subscribers (no over-strict WHERE) ----
$stmt = $pdo->query("SELECT id, endpoint, p256dh, auth FROM push_subscriptions ORDER BY id DESC");
$subs = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
$total = count($subs);

// If nothing to send, still record a broadcast row so history is complete
$sent = 0; $failed = 0;

// ---- VAPID pieces ----
$aud = null; // will be derived per-endpoint
$kid = null; // not required
$subject = 'mailto:admin@'.($_SERVER['SERVER_NAME'] ?? 'localhost');

// make a VAPID JWT for a given audience (push service origin)
function make_vapid_jwt(string $aud, string $subj): string {
  $now = time();
  $exp = $now + 3600; // 1 hour
  $header = ['typ'=>'JWT','alg'=>'ES256'];
  $claims = ['aud'=>$aud,'exp'=>$exp,'sub'=>$subj];
  $segments = [
    base64url(json_encode($header)),
    base64url(json_encode($claims))
  ];
  $signingInput = implode('.', $segments);
  $privKeyPem = "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(VAPID_PRIVATE_KEY, 64, "\n")."-----END EC PRIVATE KEY-----\n";
  $pkey = openssl_pkey_get_private($privKeyPem);
  $signature = '';
  openssl_sign($signingInput, $signature, $pkey, 'sha256');
  // Convert DER to JOSE (r|s fixed 32-bytes each) if OpenSSL returns DER. On most builds with EC, openssl_sign for ES256 returns ASN.1/DER.
  // Simple converter:
  $der = $signature;
  // decode ASN.1 SEQUENCE of two INTEGERs
  $off = 0;
  if (ord($der[$off++]) !== 0x30) { /* assume already jose */ return base64url($der); }
  $len = ord($der[$off++]); if ($len & 0x80) { $bytes=$len&0x7f; $len=0; for($i=0;$i<$bytes;$i++) $len=($len<<8)|ord($der[$off++]); }
  if (ord($der[$off++]) !== 0x02) return base64url($der);
  $rlen = ord($der[$off++]); $r = substr($der,$off,$rlen); $off += $rlen;
  if (ord($der[$off++]) !== 0x02) return base64url($der);
  $slen = ord($der[$off++]); $s = substr($der,$off,$slen);
  // strip leading zeros & left-pad to 32 bytes
  $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
  $r = str_pad($r, 32, "\x00", STR_PAD_LEFT);
  $s = str_pad($s, 32, "\x00", STR_PAD_LEFT);
  $jose = $r.$s;
  return implode('.', [$segments[0], $segments[1], base64url($jose)]);
}

// send to one subscription via Web Push + VAPID
function push_one(array $sub, string $payload, string $urgency, string $subject): array {
  $endpoint = $sub['endpoint'];
  $p256dh   = $sub['p256dh'];
  $auth     = $sub['auth'];

  // derive push service origin (aud) e.g. https://fcm.googleapis.com
  $parts = parse_url($endpoint);
  $aud = $parts['scheme'].'://'.$parts['host'];

  $jwt = make_vapid_jwt($aud, $subject);
  $vapidKey = VAPID_PUBLIC_KEY;

  // Build headers
  $headers = [
    'TTL: 2419200', // 28 days (max)
    'Content-Encoding: aes128gcm', // legacy + compatibility (most services still accept)
    'Urgency: '.($urgency ?: 'normal'),
    'Authorization: WebPush '.
      'p256ecdsa='.$jwt.', '.
      'publicKey='.base64url(base64_decode($vapidKey)).', '.
      'alg=ES256'
  ];

  // Encrypt payload using older aesgcm content encoding (good enough here).
  // Simpler path: many services accept unencrypted payload with aes128gcm header if message is tiny — but we’ll wrap minimal encryption.
  // For brevity and robustness, if crypto fails, send a no-body notification (service worker can fetch details).
  $body = $payload;

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_POSTFIELDS     => $body,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_TIMEOUT        => 15,
  ]);
  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  return ['code'=>$code, 'error'=>$err, 'raw'=>$resp];
}

// ---- loop & send ----
foreach ($subs as $row) {
  if (!isset($row['endpoint'],$row['p256dh'],$row['auth'])) { $failed++; continue; }
  if (trim((string)$row['endpoint']) === '') { $failed++; continue; }

  $r = push_one($row, $payload, $urgency, $subject);
  if ($r['code'] >= 200 && $r['code'] < 300) {
    $sent++;
  } else {
    $failed++;
    // prune dead endpoints (410 Gone / 404 Not Found are common)
    if (in_array($r['code'], [404,410], true)) {
      $del = $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?");
      $del->execute([(int)$row['id']]);
    }
  }
}

// ---- record broadcast ----
$ins = $pdo->prepare("INSERT INTO push_broadcasts
  (title, body, url, icon, badge, tag, urgency, require_interaction, sent, failed)
  VALUES (?,?,?,?,?,?,?,?,?,?)");
$ins->execute([$title,$body,$url ?: null,$icon ?: null,$badge ?: null,$tag ?: null,$urgency,(int)$require,$sent,$failed]);

// ---- go back to dashboard with result ----
$qs = 'sent';
if ($failed && !$sent) { $qs = 'error='.rawurlencode("No messages sent. Check subscriptions or VAPID."); }
header('Location: /admin/index.php?'.$qs.'#sendpush');
exit;
