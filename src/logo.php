<?php
// Логотипы банков точно под плитку (×1, ×2, ×3). Исходники у НСПК от 104 до 460 px,
// и браузерное сжатие до 40 px на экране 1× заметно мылит.
declare(strict_types=1);

namespace NspkMirror;

const LOGO_SIZES = [40, 80, 120];
const LOGO_TTL = 7 * 86400;

/** @return array{status: int, type: string, body: string, cache: string} */
function logo(int $size, string $file): array
{
    if (!in_array($size, LOGO_SIZES, true) || !preg_match('/^bank\d{12}\.png$/', $file)) {
        return ['status' => 404, 'type' => 'text/plain; charset=utf-8', 'body' => '', 'cache' => '-'];
    }
    $key = "logo/$size/$file";
    $copy = cache_read($key, LOGO_TTL);
    if ($copy !== null && $copy['fresh']) {
        return ['status' => 200, 'type' => 'image/png', 'body' => $copy['body'], 'cache' => 'HIT'];
    }
    $src = cached_get('https://' . QR_HOST . '/proxyapp/logo/' . $file, LOGO_TTL);
    if ($src['status'] !== 200) {
        return $copy !== null
            ? ['status' => 200, 'type' => 'image/png', 'body' => $copy['body'], 'cache' => 'STALE']
            : ['status' => $src['status'], 'type' => 'text/plain; charset=utf-8', 'body' => '', 'cache' => 'MISS'];
    }
    $png = resize_png($src['body'], $size);
    if ($png === null) {
        return ['status' => 200, 'type' => $src['type'], 'body' => $src['body'], 'cache' => 'RAW'];
    }
    cache_write($key, $png, 'image/png');
    return ['status' => 200, 'type' => 'image/png', 'body' => $png, 'cache' => 'MISS'];
}

/** Вписывает картинку в квадрат size×size с прозрачными полями. null — GD нет или картинка битая. */
function resize_png(string $data, int $size): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        return null;
    }
    $src = @imagecreatefromstring($data);
    if ($src === false) {
        return null;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min($size / $w, $size / $h);
    $dw = max(1, (int)round($w * $scale));
    $dh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($size, $size);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagefill($dst, 0, 0, (int)imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);

    ob_start();
    imagepng($dst, null, 9);
    return (string)ob_get_clean();
}
