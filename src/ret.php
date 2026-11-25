<?php
declare(strict_types=1);

namespace NspkMirror;

// Поля токена mx_r по строкам: куда вернуть при успехе и при неуспехе, адрес магазина, аватарка магазина.
const RETURN_FIELDS = ['ok', 'fail', 'shop', 'avatar'];
const AVATAR_SIZES = [24, 48, 72];
const AVATAR_TTL = 86400;
const AVATAR_MAX_BYTES = 2_000_000;

/**
 * Ключ для токенов mx_r из RETURN_KEY: 32 байта в hex (64 символа) или base64.
 * null — ключа нет, параметр mx_r игнорируется.
 */
function return_key(): ?string
{
    $raw = trim((string)getenv('RETURN_KEY'));
    if ($raw === '') {
        return null;
    }
    $key = strlen($raw) === 64 && ctype_xdigit($raw) ? hex2bin($raw) : base64_decode(strtr($raw, '-_', '+/'), true);
    return is_string($key) && strlen($key) === 32 ? $key : null;
}

/**
 * Токен mx_r: base64url(nonce 12 байт ‖ шифртекст ‖ тег 16 байт), AES-256-GCM, доп. данные — QRC_ID.
 * Открытый текст — поля RETURN_FIELDS через \n, пустые хвосты можно опускать.
 *
 * @param array<string, string> $data
 */
function seal_return_token(array $data, string $qrcId, string $key): string
{
    $plain = rtrim(implode("\n", array_map(static fn(string $f): string => (string)($data[$f] ?? ''), RETURN_FIELDS)), "\n");
    $nonce = random_bytes(12);
    $tag = '';
    $sealed = (string)openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, $qrcId, 16);
    return rtrim(strtr(base64_encode($nonce . $sealed . $tag), '+/', '-_'), '=');
}

/**
 * Расшифровывает mx_r. null — токен битый, от другого QR или без единого годного адреса.
 *
 * @return array{ok: string, fail: string, shop: string, avatar: string}|null
 */
function open_return_token(string $token, string $qrcId, string $key): ?array
{
    if ($token === '' || strlen($token) > 4096 || !preg_match('/^[A-Za-z0-9_-]+$/', $token)) {
        return null;
    }
    $bin = base64_decode(strtr($token, '-_', '+/'), true);
    if ($bin === false || strlen($bin) < 12 + 16 + 1) {
        return null;
    }
    $plain = openssl_decrypt(substr($bin, 12, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($bin, 0, 12), substr($bin, -16), $qrcId);
    if ($plain === false) {
        return null;
    }
    $data = array_combine(RETURN_FIELDS, array_pad(explode("\n", $plain, count(RETURN_FIELDS)), count(RETURN_FIELDS), ''));
    // Токен выпускает только владелец ключа, но схему всё равно проверяем: javascript: в Location и href недопустим.
    foreach ($data as $url) {
        if ($url !== '' && preg_match('#^https?://[^\s"<>\\\\]+$#', $url) !== 1) {
            return null;
        }
    }
    return implode('', $data) === '' ? null : $data;
}

/**
 * mx_r из query страницы вместе с самим токеном, он нужен для /r/ и /a/.
 *
 * @return array{token: string, ok: string, fail: string, shop: string, avatar: string}|null
 */
function return_info(string $query, string $qrcId): ?array
{
    $key = return_key();
    parse_str(ltrim($query, '?'), $q);
    $token = is_string($q['mx_r'] ?? null) ? $q['mx_r'] : '';
    $data = $key !== null ? open_return_token($token, $qrcId, $key) : null;
    return $data === null ? null : $data + ['token' => $token];
}

/**
 * Аватарка магазина точно под размер (×1, ×2, ×3). Качается с проверкой сертификата, только PNG, JPEG и GIF:
 * SVG с нашего домена мог бы исполнить скрипт.
 *
 * @return array{status: int, type: string, body: string, cache: string}
 */
function avatar(string $url, int $size): array
{
    if (!in_array($size, AVATAR_SIZES, true)) {
        return ['status' => 404, 'type' => 'text/plain', 'body' => '', 'cache' => '-'];
    }
    $key = 'avatar/' . $size . '/' . $url;
    $copy = cache_read($key, AVATAR_TTL);
    if ($copy !== null && $copy['fresh']) {
        return ['status' => 200, 'type' => 'image/png', 'body' => $copy['body'], 'cache' => 'HIT'];
    }
    $res = upstream_request('GET', $url, ['Accept: image/png,image/jpeg,image/gif'], null, 5, true);
    $png = $res['status'] === 200 && strlen($res['body']) <= AVATAR_MAX_BYTES ? resize_png($res['body'], $size) : null;
    if ($png === null) {
        return $copy !== null
            ? ['status' => 200, 'type' => 'image/png', 'body' => $copy['body'], 'cache' => 'STALE']
            : ['status' => 404, 'type' => 'text/plain', 'body' => '', 'cache' => 'MISS'];
    }
    cache_write($key, $png, 'image/png');
    return ['status' => 200, 'type' => 'image/png', 'body' => $png, 'cache' => 'MISS'];
}
