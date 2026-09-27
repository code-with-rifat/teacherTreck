<?php
/**
 * Build a clean circular logo mark: red emblem on white (no black plate).
 */
declare(strict_types=1);

$dir = dirname(__DIR__) . '/favicon_io';
$srcPath = $dir . '/android-chrome-512x512.png.bak';
if (!is_file($srcPath)) {
    $srcPath = $dir . '/logo-medico.jpg';
}

$ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
$src = $ext === 'jpg' || $ext === 'jpeg'
    ? imagecreatefromjpeg($srcPath)
    : imagecreatefrompng($srcPath);

if (!$src) {
    fwrite(STDERR, "Cannot read source\n");
    exit(1);
}

$sw = imagesx($src);
$sh = imagesy($src);

// Find red blob bounds
$minX = $sw;
$minY = $sh;
$maxX = 0;
$maxY = 0;
for ($y = 0; $y < $sh; $y++) {
    for ($x = 0; $x < $sw; $x++) {
        $rgba = imagecolorat($src, $x, $y);
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;
        if ($r > 130 && $r > $g + 40 && $r > $b + 40) {
            if ($x < $minX) $minX = $x;
            if ($y < $minY) $minY = $y;
            if ($x > $maxX) $maxX = $x;
            if ($y > $maxY) $maxY = $y;
        }
    }
}

$cx = (int) round(($minX + $maxX) / 2);
$cy = (int) round(($minY + $maxY) / 2);
$radius = (int) round(max($maxX - $minX, $maxY - $minY) / 2 * 1.08);
$size = $radius * 2;
$outSize = 512;

$out = imagecreatetruecolor($outSize, $outSize);
$white = imagecolorallocate($out, 255, 255, 255);
imagefilledrectangle($out, 0, 0, $outSize, $outSize, $white);

// Scale source crop into output, circular mask of red area only
$scale = $outSize / $size;
for ($y = 0; $y < $outSize; $y++) {
    for ($x = 0; $x < $outSize; $x++) {
        $dx = $x - $outSize / 2;
        $dy = $y - $outSize / 2;
        if (($dx * $dx + $dy * $dy) > ($outSize / 2) * ($outSize / 2)) {
            continue; // stay white outside circle
        }
        $sx = (int) round($cx - $radius + $x / $scale);
        $sy = (int) round($cy - $radius + $y / $scale);
        if ($sx < 0 || $sy < 0 || $sx >= $sw || $sy >= $sh) {
            continue;
        }
        $rgba = imagecolorat($src, $sx, $sy);
        $r = ($rgba >> 16) & 0xFF;
        $g = ($rgba >> 8) & 0xFF;
        $b = $rgba & 0xFF;
        // Drop near-black frame → white so no black plate
        if ($r < 40 && $g < 40 && $b < 40) {
            continue;
        }
        $c = imagecolorallocate($out, $r, $g, $b);
        imagesetpixel($out, $x, $y, $c);
    }
}

$jpg = $dir . '/logo-medico.jpg';
$png = $dir . '/logo-medico.png';
imagejpeg($out, $jpg, 93);
imagepng($out, $png);
imagedestroy($src);
imagedestroy($out);

echo "Wrote mark {$jpg}\n";
