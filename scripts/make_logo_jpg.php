<?php
/**
 * Composite original logo onto solid white JPG for clear display.
 */
declare(strict_types=1);

$dir = dirname(__DIR__) . '/favicon_io';
$srcPng = $dir . '/android-chrome-512x512.png.bak';
if (!is_file($srcPng)) {
    $srcPng = $dir . '/android-chrome-512x512.png';
}

$src = imagecreatefrompng($srcPng);
if (!$src) {
    fwrite(STDERR, "Cannot read source PNG\n");
    exit(1);
}

$w = imagesx($src);
$h = imagesy($src);

$out = imagecreatetruecolor($w, $h);
$white = imagecolorallocate($out, 255, 255, 255);
imagefilledrectangle($out, 0, 0, $w, $h, $white);
imagealphablending($out, true);
imagecopy($out, $src, 0, 0, 0, 0, $w, $h);

$jpg = $dir . '/logo-medico.jpg';
imagejpeg($out, $jpg, 92);

// Also write a clean white-bg PNG for favicon uses that need PNG
$pngOut = $dir . '/logo-medico.png';
imagepng($out, $pngOut);

imagedestroy($src);
imagedestroy($out);

echo "Wrote {$jpg}\n";
echo "Wrote {$pngOut}\n";
