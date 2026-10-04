<?php
// /admin/qr_poster.php — Printable A4 QR poster
declare(strict_types=1);

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../includes/admin_auth.php';
admin_require($pdo);

/* ---- helpers (guarded to avoid redeclare) ---- */
if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('site_origin')) {
  function site_origin(): string {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
    $scheme = $isHttps ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');
    return $scheme.'://'.$host;
  }
}
if (!function_exists('abs_url')) {
  function abs_url(string $path): string {
    return preg_match('~^https?://~i', $path) ? $path : site_origin().($path[0] === '/' ? $path : '/'.$path);
  }
}

/* ---- content ---- */
$targetUrl = abs_url('/');
$qrSrc     = '/icon.php?qr=' . rawurlencode($targetUrl) . '&s=680&fmt=svg&level=M&margin=2';
// use the live logo in the site root
$logoUrl   = abs_url('/icons/icon-192.png');

?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>QR Poster · Deliveries</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  /* A4 print & screen-friendly styles */
  @page { size: A4; margin: 18mm; }
  :root{
    --ink:#0b1220; --muted:#4b5563; --blue:#0a84ff;
  }
  html,body{background:#f7f9fc;color:var(--ink);font:14px/1.45 system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;margin:0}
  .sheet{max-width:800px;margin:24px auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:22px 24px;box-shadow:0 10px 30px rgba(2,6,23,.08)}
  header{display:flex;align-items:center;gap:16px;margin-bottom:12px}
  .brand{display:flex;align-items:center;gap:14px}
  .brand img{height:60px;width:auto}
  h1{font-size:28px;margin:0}
  .muted{color:var(--muted)}
  .qrwrap{display:grid;grid-template-columns:1fr;justify-items:center;margin:10px 0 6px}
  .qr{display:inline-block;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:10px}
  .qr img{display:block;width:420px;height:420px}
  .cta{margin-top:12px;text-align:center}
  .link{font-size:20px;font-weight:800;letter-spacing:.2px}
  .hint{margin-top:6px;font-size:12px}
  .footer{margin-top:18px;text-align:center;color:#6b7280;font-size:12px}
  .actions{display:flex;gap:8px;justify-content:flex-end;margin:8px 0 0}
  .btn{appearance:none;border:1px solid #cbd5e1;background:#fff;color:#111827;border-radius:10px;padding:8px 12px;font-weight:700;cursor:pointer}
  .btn.primary{background:var(--blue);border-color:var(--blue);color:#fff}
  @media print{
    html,body{background:#fff}
    .sheet{box-shadow:none;border:none;border-radius:0;margin:0;max-width:none;padding:0}
    .actions{display:none!important}
  }
</style>
</head>
<body>
  <div class="sheet" role="document" aria-label="Printable QR poster">
    <div class="actions noprint">
      <button class="btn" onclick="window.history.back()">Back</button>
      <button class="btn primary" onclick="window.print()">Print</button>
    </div>

    <header>
      <div class="brand">
        <img src="<?= h($logoUrl) ?>" alt="Site Deliveries logo">
        <div>
          <h1>Site Deliveries</h1>
          <div class="muted">Book delivery slots · Gate QR · Attach RAMS/PO</div>
        </div>
      </div>
    </header>

    <div class="qrwrap" aria-live="polite">
      <div class="qr" aria-label="QR code linking to deliveries site">
        <img src="<?= h($qrSrc) ?>" alt="Scan to open: <?= h($targetUrl) ?>">
      </div>
      <div class="cta">
        <div class="link"><?= h($targetUrl) ?></div>
        <div class="hint">Point your phone camera at the QR to open the booking page.</div>
      </div>
    </div>

    <div class="footer">
      © <?= date('Y') ?> Site Deliveries · Printed from Deliveries Admin
    </div>
  </div>
</body>
</html>
