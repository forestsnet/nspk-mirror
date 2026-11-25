<?php
// Запросы к апстримам НСПК и cbrpay.ru и дисковый кэш для статики.
declare(strict_types=1);

namespace NspkMirror;

const CACHE_DIR = '/var/cache/nspk-mirror';

/**
 * Запрос к апстриму. Сертификат сознательно не проверяем: цепочка Russian Trusted Root CA,
 * корень Минцифры в образ не кладём. status = 0 означает сетевую ошибку, текст в error.
 *
 * @param list<string> $headers
 * @return array{status: int, headers: list<array{0: string, 1: string}>, body: string, error: string}
 */
function upstream_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 20): array
{
    $responseHeaders = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => array_merge(['Expect:'], $headers),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_ENCODING => '',
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
            if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) {
                $responseHeaders = []; // начался новый ответ (например, после 1xx), старые заголовки не нужны
            } elseif (($p = strpos($line, ':')) !== false) {
                $responseHeaders[] = [strtolower(trim(substr($line, 0, $p))), trim(substr($line, $p + 1))];
            }
            return strlen($line);
        },
    ]);
    if ($method === 'HEAD') {
        curl_setopt($ch, CURLOPT_NOBODY, true);
    } elseif ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$body);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    $result = curl_exec($ch);
    if ($result === false) {
        return ['status' => 0, 'headers' => [], 'body' => '', 'error' => curl_error($ch) ?: 'curl error'];
    }
    return [
        'status' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'headers' => $responseHeaders,
        'body' => (string)$result,
        'error' => '',
    ];
}

/** @param list<array{0: string, 1: string}> $headers */
function header_value(array $headers, string $name): ?string
{
    foreach ($headers as [$n, $v]) {
        if ($n === $name) {
            return $v;
        }
    }
    return null;
}

/**
 * Копия из кэша и её свежесть относительно ttl. null — копии нет.
 *
 * @return array{body: string, type: string, fresh: bool}|null
 */
function cache_read(string $key, int $ttl): ?array
{
    $base = CACHE_DIR . '/' . sha1($key);
    $meta = @file_get_contents("$base.json");
    $body = @file_get_contents("$base.body");
    if ($meta === false || $body === false) {
        return null;
    }
    $meta = json_decode($meta, true);
    if (!is_array($meta)) {
        return null;
    }
    return [
        'body' => $body,
        'type' => (string)($meta['type'] ?? 'application/octet-stream'),
        'fresh' => time() - (int)($meta['at'] ?? 0) < $ttl,
    ];
}

/** Пишет копию атомарно: во временный файл, затем rename. Ошибка записи ответу не мешает. */
function cache_write(string $key, string $body, string $type): void
{
    if (!is_dir(CACHE_DIR) && !@mkdir(CACHE_DIR, 0775, true)) {
        return;
    }
    $base = CACHE_DIR . '/' . sha1($key);
    $tmp = $base . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (@file_put_contents($tmp, $body) !== false) {
        @rename($tmp, "$base.body");
    }
    if (@file_put_contents($tmp, (string)json_encode(['at' => time(), 'type' => $type, 'key' => $key])) !== false) {
        @rename($tmp, "$base.json");
    }
}

/**
 * GET статики через кэш. Свежая копия отдаётся сразу, протухшая — если апстрим не ответил 200.
 *
 * @param list<string> $headers
 * @return array{status: int, type: string, body: string, cache: string}
 */
function cached_get(string $url, int $ttl, array $headers = [], int $timeout = 10): array
{
    $copy = cache_read($url, $ttl);
    if ($copy !== null && $copy['fresh']) {
        return ['status' => 200, 'type' => $copy['type'], 'body' => $copy['body'], 'cache' => 'HIT'];
    }
    $res = upstream_request('GET', $url, $headers ?: ['Accept: */*'], null, $timeout);
    if ($res['status'] === 200) {
        $type = header_value($res['headers'], 'content-type') ?? 'application/octet-stream';
        cache_write($url, $res['body'], $type);
        return ['status' => 200, 'type' => $type, 'body' => $res['body'], 'cache' => 'MISS'];
    }
    if ($copy !== null) {
        return ['status' => 200, 'type' => $copy['type'], 'body' => $copy['body'], 'cache' => 'STALE'];
    }
    if ($res['status'] === 0) {
        error_log(sprintf('nspk-mirror: GET %s: %s', $url, $res['error']));
    }
    return ['status' => $res['status'] ?: 502, 'type' => 'text/plain; charset=utf-8', 'body' => '', 'cache' => 'MISS'];
}
