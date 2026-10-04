<?php
// /push/time_diag.php
// Shows server time (UTC & local) and client/server skew.
// If ?client= is missing, auto-redirect adds your browser's current epoch.

if (!isset($_GET['client'])) {
  // Simple HTML that reloads with ?client=<epoch>
  ?><!doctype html><meta charset="utf-8">
  <title>Time diag</title>
  <pre>Loading…</pre>
  <script>
    const ep = Math.floor(Date.now()/1000);
    const url = new URL(location.href);
    url.searchParams.set('client', String(ep));
    location.replace(url.toString());
  </script><?php
  exit;
}

header('Content-Type: text/plain; charset=utf-8');

$serverUtc   = new DateTimeImmutable('now', new DateTimeZone('UTC'));
$serverLocal = new DateTimeImmutable('now'); // server default tz
$clientEpoch = (int)$_GET['client'];
$clientUtc   = DateTimeImmutable::createFromFormat('U', (string)$clientEpoch, new DateTimeZone('UTC'));

echo "Server (UTC):   ".$serverUtc->format('Y-m-d H:i:s')." UTC\n";
echo "Server (local): ".$serverLocal->format('Y-m-d H:i:s T')."\n";
echo "Client (UTC):   ".$clientUtc->format('Y-m-d H:i:s')." UTC\n";

$skew = abs($serverUtc->getTimestamp() - $clientUtc->getTimestamp());
echo "Skew (abs):     {$skew} seconds\n";
echo ($skew <= 60 ? "OK: skew <= 60s\n" : "WARN: skew > 60s (fix NTP)\n");
