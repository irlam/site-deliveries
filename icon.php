<?php
// /icon.php
declare(strict_types=1);

if (isset($_GET['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
}

function respond_text(int $code, string $msg): void {
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo $msg;
    exit;
}

// ---- Find Composer autoloader ----
$autoloadPaths = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
];
$autoload = null;
foreach ($autoloadPaths as $p) {
    if (is_file($p)) { $autoload = $p; break; }
}
if ($autoload === null) {
    respond_text(500, "QR generator not initialised: vendor/autoload.php not found.\nTried:\n - ".$autoloadPaths[0]."\n - ".$autoloadPaths[1]);
}
require_once $autoload;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

$qrParam = isset($_GET['qr']) ? (string)$_GET['qr'] : '';
if ($qrParam !== '') {
    try {
        $payload = urldecode($qrParam);

        $size   = isset($_GET['s']) ? max(80, min((int)$_GET['s'], 1000)) : 180;   // px
        $fmt    = isset($_GET['fmt']) ? strtolower((string)$_GET['fmt']) : 'svg';  // svg|png
        $levelS = isset($_GET['level']) ? strtoupper((string)$_GET['level']) : 'M';// L|M|Q|H
        $margin = isset($_GET['margin']) ? max(0, min((int)$_GET['margin'], 10)) : 2;

        // Map string → int constant (required by library)
        $eccMap = [
            'L' => QRCode::ECC_L,
            'M' => QRCode::ECC_M,
            'Q' => QRCode::ECC_Q,
            'H' => QRCode::ECC_H,
        ];
        $ecc = $eccMap[$levelS] ?? QRCode::ECC_M;

        // Strong caching
        $etag = '"' . sha1($payload.'|'.$size.'|'.$fmt.'|'.$ecc.'|'.$margin) . '"';
        header('ETag: '.$etag);
        header('Cache-Control: public, max-age=31536000, immutable');
        if (isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) === $etag) {
            http_response_code(304);
            exit;
        }

        $options = new QROptions([
            'outputType'    => ($fmt === 'png') ? QRCode::OUTPUT_IMAGE_PNG : QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel'      => $ecc,       // <-- int constant, not string
            'scale'         => 1,          // size via width/height
            'imageBase64'   => false,
            'quietzoneSize' => $margin,
            'addQuietzone'  => true,
            // SVG polish:
            'markupDark'    => '#000000',
            'markupLight'   => '#FFFFFF',
            'svgViewBox'    => true,
            'svgOpacity'    => 1.0,
        ]);

        $qrcode = (new QRCode($options))->render($payload);

        if ($fmt === 'png') {
            header('Content-Type: image/png');
            echo $qrcode;
        } else {
            header('Content-Type: image/svg+xml; charset=utf-8');
            if (strpos($qrcode, '<svg') === 0) {
                $qrcode = preg_replace('~<svg([^>]+)>~', '<svg$1 width="'.$size.'" height="'.$size.'">', $qrcode, 1);
            }
            echo $qrcode;
        }
        exit;
    }
    catch (Throwable $e) {
        if (isset($_GET['debug'])) {
            respond_text(500, "QR generation error: ".$e->getMessage());
        }
        respond_text(500, "QR generation failed.");
    }
}

// --- Static icon passthrough ---
$base = __DIR__ . '/icons/';
$fname = isset($_GET['f']) ? basename((string)$_GET['f']) : '';
$path = realpath($base . $fname);

if (!$fname || !$path || strncmp($path, realpath($base), strlen(realpath($base))) !== 0 || !is_file($path)) {
    respond_text(404, "Not found");
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$mime = match ($ext) {
    'svg'         => 'image/svg+xml',
    'png'         => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif'         => 'image/gif',
    default       => 'application/octet-stream',
};
header('Content-Type: '.$mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=31536000, immutable');

$lastModTs = filemtime($path) ?: time();
$lastMod   = gmdate('D, d M Y H:i:s', $lastModTs).' GMT';
header('Last-Modified: '.$lastMod);

if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && @strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE']) >= $lastModTs) {
    http_response_code(304);
    exit;
}

readfile($path);
