<?php
/**
 * /qr_local.php
 * Offline QR generator wrapper (PNG).
 * Requires: /lib/qrlib.php  (single-file vendor, no composer)
 *
 * Usage:
 *   <img src="/qr_local.php?text=...&size=160">
 */

declare(strict_types=1);

// ---- config
$LIB = __DIR__ . '/lib/qrlib.php';
$DEFAULT_SIZE = 160;     // px
$EC_LEVEL = 'M';         // L,M,Q,H
$MARGIN   = 1;           // modules

// ---- params
$text = isset($_GET['text']) ? (string)$_GET['text'] : '';
$size = isset($_GET['size']) ? (int)$_GET['size'] : $DEFAULT_SIZE;
$size = max(60, min(1000, $size));

if ($text === '') {
  http_response_code(400);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "Missing ?text= parameter";
  exit;
}

if (!file_exists($LIB)) {
  // Friendly message so you know what to upload
  http_response_code(503);
  header('Content-Type: text/plain; charset=UTF-8');
  echo "QR library not found.\nPlease upload vendor file to: /lib/qrlib.php";
  exit;
}

// library produces PNG directly to output buffer
require_once $LIB;

// qrlib draws into a module grid; we scale to final pixel size via 'pixelPerPoint'
$levelMap = ['L'=>QR_ECLEVEL_L, 'M'=>QR_ECLEVEL_M, 'Q'=>QR_ECLEVEL_Q, 'H'=>QR_ECLEVEL_H];
$level = $levelMap[$EC_LEVEL] ?? QR_ECLEVEL_M;

header('Content-Type: image/png');
// QRcode::png(string $text, $outfile=false, $level=QR_ECLEVEL_L, $size=3, $margin=4)
QRcode::png($text, false, $level, /*size*/ max(1, (int)round($size/40)), $MARGIN);
