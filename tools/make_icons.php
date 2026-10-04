<?php
// /tools/make_icons.php
declare(strict_types=1);

// Creates /icons/icon-192.png and /icons/icon-512.png with a simple logo
$targets = [
  ['w'=>192, 'h'=>192, 'file'=>__DIR__ . '/../icons/icon-192.png'],
  ['w'=>512, 'h'=>512, 'file'=>__DIR__ . '/../icons/icon-512.png'],
];

$ok = true;
foreach ($targets as $t) {
    $w = $t['w']; $h = $t['h'];
    if (!is_dir(dirname($t['file']))) {
        @mkdir(dirname($t['file']), 0775, true);
    }

    $im = imagecreatetruecolor($w, $h);
    if (!$im) { $ok = false; break; }
    imagealphablending($im, true);
    imagesavealpha($im, true);

    // Colors
    $bg  = imagecolorallocate($im, 11, 18, 32);   // dark bg
    $ac  = imagecolorallocate($im, 14,165,233);   // accent
    $wh  = imagecolorallocate($im, 245, 249, 255);// light

    // Background
    imagefilledrectangle($im, 0, 0, $w, $h, $bg);

    // Accent circle
    $r = (int)round(min($w,$h)*0.42);
    imagefilledellipse($im, (int)($w/2), (int)($h/2), $r*2, $r*2, $ac);

    // A simple "hard-hat" block (stylized)
    $hatW = (int)round($w*0.56);
    $hatH = (int)round($h*0.30);
    $x = (int)(($w - $hatW)/2);
    $y = (int)(($h - $hatH)/2);
    imagefilledrectangle($im, $x, $y, $x+$hatW, $y+$hatH, $wh);
    imagefilledrectangle($im, $x, $y+$hatH- (int)round($hatH*0.28), $x+$hatW, $y+$hatH, $bg);

    // Save
    imagepng($im, $t['file']);
    imagedestroy($im);
}

header('Content-Type: text/plain; charset=UTF-8');
echo $ok ? "Icons written to /icons/icon-192.png and /icons/icon-512.png\n" : "Failed to generate icons.\n";
