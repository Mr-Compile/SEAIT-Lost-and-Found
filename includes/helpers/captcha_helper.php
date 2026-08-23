<?php

require_once __DIR__ . '/../../captcha/verify_captcha.php';

function captchaResolveFontPath(): ?string
{
    $windir = getenv('WINDIR') ?: 'C:\\Windows';

    $candidates = [
        $windir . '\\Fonts\\arialbd.ttf',
        $windir . '\\Fonts\\arial.ttf',
        $windir . '\\Fonts\\consolab.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
    ];

    foreach ($candidates as $fontPath) {
        if (is_file($fontPath)) {
            return $fontPath;
        }
    }

    return null;
}

function captchaApplyWaveDistortion($sourceImage, int $width, int $height, int $backgroundColor)
{
    $distorted = imagecreatetruecolor($width, $height);
    imagefill($distorted, 0, 0, $backgroundColor);

    $phaseX = random_int(0, 1000) / 100;
    $phaseY = random_int(0, 1000) / 100;

    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            $srcX = $x + (int) round(sin(($y / 11) + $phaseX) * 3);
            $srcY = $y + (int) round(sin(($x / 17) + $phaseY) * 3);

            if ($srcX >= 0 && $srcX < $width && $srcY >= 0 && $srcY < $height) {
                $pixelColor = imagecolorat($sourceImage, $srcX, $srcY);
                imagesetpixel($distorted, $x, $y, $pixelColor);
            } else {
                imagesetpixel($distorted, $x, $y, $backgroundColor);
            }
        }
    }

    return $distorted;
}

function captchaRenderSvgFallback(string $captchaCode): void
{
    $width = 220;
    $height = 80;
    $chars = str_split($captchaCode);

    $svg = [];
    $svg[] = sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d" viewBox="0 0 %d %d" role="img" aria-label="captcha image">', $width, $height, $width, $height);
    $svg[] = '<defs><linearGradient id="bg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#f6f8fc"/><stop offset="100%" stop-color="#e1e7f0"/></linearGradient></defs>';
    $svg[] = '<rect width="100%" height="100%" fill="url(#bg)" rx="8" ry="8"/>';

    for ($x = 0; $x < $width; $x += 14) {
        $svg[] = sprintf('<line x1="%d" y1="0" x2="%d" y2="%d" stroke="rgba(120,130,160,0.25)" stroke-width="1"/>', $x, $x, $height);
    }
    for ($y = 0; $y < $height; $y += 14) {
        $svg[] = sprintf('<line x1="0" y1="%d" x2="%d" y2="%d" stroke="rgba(120,130,160,0.25)" stroke-width="1"/>', $y, $width, $y);
    }

    for ($i = 0; $i < 11; $i++) {
        $x1 = random_int(0, $width);
        $y1 = random_int(0, $height);
        $x2 = random_int(0, $width);
        $y2 = random_int(0, $height);
        $svg[] = sprintf('<line x1="%d" y1="%d" x2="%d" y2="%d" stroke="rgba(%d,%d,%d,0.25)" stroke-width="1"/>', $x1, $y1, $x2, $y2, random_int(90, 150), random_int(90, 150), random_int(90, 150));
    }

    for ($i = 0; $i < 200; $i++) {
        $cx = random_int(0, $width);
        $cy = random_int(0, $height);
        $r = random_int(1, 2);
        $svg[] = sprintf('<circle cx="%d" cy="%d" r="%d" fill="rgba(%d,%d,%d,0.2)"/>', $cx, $cy, $r, random_int(100, 170), random_int(100, 170), random_int(100, 170));
    }

    $x = 14;
    foreach ($chars as $char) {
        $fontSize = random_int(24, 34);
        $rotate = random_int(-30, 30);
        $y = random_int(48, 67);
        $fill = sprintf('rgb(%d,%d,%d)', random_int(20, 80), random_int(20, 80), random_int(30, 100));

        $svg[] = sprintf(
            '<text x="%d" y="%d" font-family="Verdana, Arial, sans-serif" font-size="%d" font-weight="700" fill="%s" transform="rotate(%d %d %d)">%s</text>',
            $x,
            $y,
            $fontSize,
            $fill,
            $rotate,
            $x,
            $y,
            htmlspecialchars($char, ENT_QUOTES, 'UTF-8')
        );

        $x += 30;
    }

    $svg[] = '</svg>';

    header('Content-Type: image/svg+xml; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo implode('', $svg);
}

function captchaRenderImage(string $captchaCode): void
{
    if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
        captchaRenderSvgFallback($captchaCode);
        return;
    }

    $width = 220;
    $height = 80;

    $image = imagecreatetruecolor($width, $height);

    // Build a soft background gradient first so the text stays readable.
    $top = [246, 248, 252];
    $bottom = [225, 231, 240];

    for ($y = 0; $y < $height; $y++) {
        $ratio = $y / max(1, ($height - 1));
        $r = (int) round($top[0] + (($bottom[0] - $top[0]) * $ratio));
        $g = (int) round($top[1] + (($bottom[1] - $top[1]) * $ratio));
        $b = (int) round($top[2] + (($bottom[2] - $top[2]) * $ratio));
        $lineColor = imagecolorallocate($image, $r, $g, $b);
        imageline($image, 0, $y, $width, $y, $lineColor);
    }

    $gridColor = imagecolorallocatealpha($image, 170, 176, 192, 90);
    for ($x = 0; $x < $width; $x += 14) {
        imageline($image, $x, 0, $x, $height, $gridColor);
    }
    for ($y = 0; $y < $height; $y += 14) {
        imageline($image, 0, $y, $width, $y, $gridColor);
    }

    $fontPath = captchaResolveFontPath();
    $characters = str_split($captchaCode);
    $textCount = max(1, count($characters));
    $slotWidth = (int) floor(($width - 28) / $textCount);

    $x = 14;
    foreach ($characters as $character) {
        $fontSize = random_int(24, 34);
        $angle = random_int(-30, 30);
        $y = random_int(46, 66);
        $textColor = imagecolorallocate($image, random_int(30, 90), random_int(30, 90), random_int(30, 110));

        if ($fontPath !== null && function_exists('imagettftext')) {
            imagettftext($image, $fontSize, $angle, $x, $y, $textColor, $fontPath, $character);
        } else {
            imagestring($image, 5, $x, random_int(20, 36), $character, $textColor);
        }

        $x += max(24, $slotWidth);
    }

    // Add heavy noise lines.
    for ($i = 0; $i < 13; $i++) {
        $noiseColor = imagecolorallocatealpha($image, random_int(90, 160), random_int(90, 160), random_int(90, 170), random_int(70, 105));
        imageline(
            $image,
            random_int(0, $width),
            random_int(0, $height),
            random_int(0, $width),
            random_int(0, $height),
            $noiseColor
        );
    }

    // Add random dots for additional distortion.
    for ($i = 0; $i < 550; $i++) {
        $dotColor = imagecolorallocatealpha($image, random_int(85, 150), random_int(85, 150), random_int(85, 160), random_int(85, 120));
        imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $dotColor);
    }

    $bgDistortionColor = imagecolorallocate($image, 236, 242, 250);
    $distorted = captchaApplyWaveDistortion($image, $width, $height, $bgDistortionColor);

    imagedestroy($image);

    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');

    imagepng($distorted);
    imagedestroy($distorted);
}

function captchaOutputImage(bool $forceNew = false): void
{
    $captchaCode = captchaGetCurrentCode($forceNew);
    captchaRenderImage($captchaCode);
}

// Allow direct browser access to this helper as an image endpoint.
if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    $forceNew = isset($_GET['refresh']) || isset($_GET['init']) || isset($_GET['new']);
    captchaOutputImage($forceNew);
    exit;
}
