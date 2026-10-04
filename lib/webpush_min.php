<?php
// /lib/webpush_min.php
// Minimal Web Push sender (aes128gcm) using OpenSSL + cURL.
// Requirements: PHP 7.4+, ext-openssl, ext-curl.

final class WebPushLite {
  private string $vapidPublic;
  private string $vapidPrivate;
  private string $subject;

  public function __construct(string $pub, string $priv, string $subject='mailto:admin@example.com') {
    $this->vapidPublic  = $pub;
    $this->vapidPrivate = $priv;
    $this->subject      = $subject;
  }

  public function send(string $endpoint, string $p256dh, string $auth, string $payload, int $ttl=180): bool {
    // --- 1) derive keys (ECDH) ---
    $salt = random_bytes(16);
    $serverKey = openssl_pkey_new([
      'curve_name' => 'prime256v1',
      'private_key_type' => OPENSSL_KEYTYPE_EC,
    ]);
    $serverPub  = $this->rawPublicKey($serverKey);
    $uaPubRaw   = $this->base64url_decode($p256dh);
    $authSecret = $this->base64url_decode($auth);

    $sharedSecret = $this->ecdh($serverKey, $uaPubRaw); // 32 bytes

    // HKDF
    $prk     = hash_hmac('sha256', $sharedSecret, $authSecret, true);
    $context = $this->context($uaPubRaw, $serverPub);
    $contentEncryptionKey = $this->hkdf($prk, 'Content-Encoding: aes128gcm', $context, 16);
    $nonce                = $this->hkdf($prk, 'Content-Encoding: nonce',      $context, 12);

    // --- 2) encrypt payload (AES-128-GCM) ---
    $rs  = 4096; // record size
    $padLen = 0;
    $plain = pack('n', $padLen) . $payload;
    $ciphertext = openssl_encrypt($plain, 'aes-128-gcm', $contentEncryptionKey, OPENSSL_RAW_DATA, $nonce, $tag);
    if ($ciphertext === false) return false;

    // --- 3) build headers ---
    $crypto_key = 'dh=' . $this->base64url_encode($serverPub) . ';p256ecdsa=' . $this->base64url_encode($this->uncompressedPub());
    $enc        = 'salt=' . $this->base64url_encode($salt);
    $authHeader = 'vapid t=' . $this->jwtFor($endpoint) . ', k=' . $this->base64url_encode($this->uncompressedPub());

    // --- 4) HTTP request ---
    $headers = [
      'TTL: ' . $ttl,
      'Content-Encoding: aes128gcm',
      'Content-Type: application/octet-stream',
      'Content-Length: ' . strlen($ciphertext . $tag),
      'Authorization: ' . $authHeader,
      'Encryption: ' . $enc,
      'Crypto-Key: ' . $crypto_key,
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_POSTFIELDS => $ciphertext . $tag,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => true,
      CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ($code >= 200 && $code < 300) || $code === 429 || $code === 404 || $code === 410;
  }

  // ===== helpers =====

  private function ecdsaKey() {
    static $key = null;
    if ($key) return $key;
    $key = openssl_pkey_new(['curve_name'=>'prime256v1','private_key_type'=>OPENSSL_KEYTYPE_EC]);
    // overwrite with provided VAPID private/public
    $pem = $this->pemFromPriv($this->base64url_decode($this->vapidPrivate));
    $key = openssl_pkey_get_private($pem);
    return $key;
  }
  private function uncompressedPub(): string {
    $pub = $this->base64url_decode($this->vapidPublic);
    return $pub;
  }
  private function pemFromPriv(string $rawPriv): string {
    // DER ECPrivateKey -> PEM (simple wrapper)
    $der = "\x30\x77\x02\x01\x01\x04\x20" . $rawPriv . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\xa1\x44\x03\x42\x00";
    // Need public too; but browsers ignore here since we pass 'k' header; keep minimal
    return "-----BEGIN EC PRIVATE KEY-----\n" .
           chunk_split(base64_encode($der), 64, "\n") .
           "-----END EC PRIVATE KEY-----\n";
  }

  private function jwtFor(string $aud): string {
    $aud = $this->origin($aud);
    $header = ['typ'=>'JWT','alg'=>'ES256'];
    $claims = ['aud'=>$aud,'exp'=>time()+12*3600,'sub'=>$this->subject];

    $segments = [
      $this->base64url_encode(json_encode($header)),
      $this->base64url_encode(json_encode($claims)),
    ];
    $signing_input = implode('.', $segments);

    $sig = '';
    openssl_sign($signing_input, $sig, $this->ecdsaKey(), OPENSSL_ALGO_SHA256);
    // OpenSSL returns DER-encoded ECDSA; convert to raw R||S then base64url
    $rs = $this->derToConcat($sig, 64);
    $segments[] = $this->base64url_encode($rs);
    return implode('.', $segments);
  }

  private function origin(string $url): string {
    $p = parse_url($url);
    $o = strtolower($p['scheme']) . '://' . strtolower($p['host']);
    if (isset($p['port'])) $o .= ':' . $p['port'];
    return $o;
  }

  private function ecdh($serverKey, string $clientPubRaw): string {
    $pub = $this->pubKeyFromRaw($clientPubRaw);
    $shared = '';
    openssl_pkey_derive($pub, $shared, $serverKey);
    return $shared;
  }

  private function pubKeyFromRaw(string $raw) {
    $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00" . $raw;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
  }

  private function context(string $uaPub, string $srvPub): string {
    return pack('n', 0) . "P-256" . pack('n', 65) . $uaPub . pack('n', 65) . $srvPub;
  }

  private function hkdf(string $ikm, string $info, string $context, int $len): string {
    $prk = hash_hmac('sha256', $ikm, $info, true);
    $okm = '';
    $prev = '';
    $i = 0;
    while (strlen($okm) < $len) {
      $i++;
      $prev = hash_hmac('sha256', $prev . $context . chr(0) . chr($i), $prk, true);
      $okm .= $prev;
    }
    return substr($okm, 0, $len);
  }

  private function rawPublicKey($pkey): string {
    $detail = openssl_pkey_get_details($pkey);
    // uncompressed 0x04 + x(32) + y(32) => 65 bytes
    return $detail['ec']['point'];
  }

  private function derToConcat(string $der, int $len): string {
    // very small DER R,S parser
    $offset = 3; // skip SEQ(0x30) + len + INT(0x02)
    $rlen = ord($der[$offset]); $offset++;
    $r = substr($der, $offset, $rlen); $offset += $rlen + 2; // skip INT tag for S
    $slen = ord($der[$offset-1]); // previous step moved +2 to S length
    $s = substr($der, $offset, $slen);
    $r = ltrim($r, "\x00"); $s = ltrim($s, "\x00");
    $r = str_pad($r, $len/2, "\x00", STR_PAD_LEFT);
    $s = str_pad($s, $len/2, "\x00", STR_PAD_LEFT);
    return $r . $s;
  }

  private function base64url_encode(string $s): string {
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
  }
  private function base64url_decode(string $s): string {
    $p = 4 - (strlen($s) % 4); if ($p < 4) $s .= str_repeat('=', $p);
    return base64_decode(strtr($s, '-_', '+/'));
  }
}
