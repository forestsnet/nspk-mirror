<?php
// Чистая логика зеркала qr.nspk.ru: куда проксировать запрос и как переписать адреса в ответе.
declare(strict_types=1);

namespace NspkMirror;

/** Страница оплаты по QR и её статика. */
const QR_HOST = 'qr.nspk.ru';

/**
 * API-хосты, к которым страница ходит напрямую. Их сертификаты тоже выпущены Russian Trusted Sub CA,
 * поэтому они проксируются с того же домена под /_cbr/<имя>/.
 */
const CBR_PREFIX = '/_cbr/';
const CBR_HOSTS = [
    'upc' => 'upc.cbrpay.ru',       // payment-link API, baseURL из proxyapp/apiConfig.json
    'widget' => 'widget.cbrpay.ru', // запасной payment-link API, зашит в js/index.js
    'crt' => 'crt.cbrpay.ru',       // проверка certBanner. Не прошла — страница просит ставить сертификат Минцифры
];

/**
 * Путь запроса к зеркалу → [хост апстрима, путь с query, префикс на зеркале].
 * null, если под /_cbr/ запрошено неизвестное имя.
 *
 * @return array{0: string, 1: string, 2: string}|null
 */
function route(string $uri): ?array
{
    if (str_starts_with($uri, CBR_PREFIX)) {
        $rest = substr($uri, strlen(CBR_PREFIX));
        $cut = strcspn($rest, '/?');
        $name = substr($rest, 0, $cut);
        if (!isset(CBR_HOSTS[$name])) {
            return null;
        }
        $path = substr($rest, $cut);
        if ($path === '' || $path[0] === '?') {
            $path = '/' . $path;
        }
        return [CBR_HOSTS[$name], $path, CBR_PREFIX . $name];
    }
    // Страница НСПК целиком, запасной вариант: /nspk/<QRC_ID> и её относительные ресурсы.
    if (str_starts_with($uri, '/nspk/')) {
        return [QR_HOST, substr($uri, 5), '/nspk'];
    }
    // На хостах с «localhost» в имени страница НСПК берёт статику с ${origin}/qr/.
    if (str_starts_with($uri, '/qr/')) {
        return [QR_HOST, substr($uri, 3), ''];
    }
    return [QR_HOST, $uri === '' ? '/' : $uri, ''];
}

/**
 * Переписывает адреса апстримов в теле ответа (HTML, JS, JSON), чтобы браузер ходил только на зеркало.
 *
 * qr.nspk.ru переписывается только со слэшем на конце. js/index.js собирает диплинк
 * bankXXXXXXXXXXXX://qr.nspk.ru/<QRC_ID> из голого литерала "https://qr.nspk.ru",
 * а приложения банков принимают там только qr.nspk.ru.
 */
function rewrite_body(string $body, string $origin): string
{
    return strtr($body, body_map($origin));
}

/**
 * JSON (статика НСПК и ответы API cbrpay.ru): переписываем только каталоги статики qr.nspk.ru
 * (логотипы банков и сервисов) и хосты cbrpay.ru. Шаблоны ссылок в банки из members API
 * не трогаем, включая возможные universal link вида https://qr.nspk.ru/{QRC_ID}.
 */
function rewrite_json(string $body, string $origin): string
{
    return strtr($body, json_map($origin));
}

/** Каталоги статики qr.nspk.ru, на которые ссылаются JSON-ответы. */
const QR_ASSET_DIRS = ['proxyapp', 'static'];

/** @return array<string, string> */
function body_map(string $origin): array
{
    return with_escaped(['https://' . QR_HOST . '/' => $origin . '/'] + cbr_map($origin));
}

/** @return array<string, string> */
function json_map(string $origin): array
{
    $map = [];
    foreach (QR_ASSET_DIRS as $dir) {
        $map['https://' . QR_HOST . '/' . $dir . '/'] = $origin . '/' . $dir . '/';
    }
    return with_escaped($map + cbr_map($origin));
}

/** @return array<string, string> */
function cbr_map(string $origin): array
{
    $map = [];
    foreach (CBR_HOSTS as $name => $host) {
        $map['https://' . $host] = $origin . CBR_PREFIX . $name;
    }
    return $map;
}

/**
 * Добавляет те же адреса с экранированными слэшами, как их пишет JSON ("https:\/\/qr.nspk.ru\/").
 *
 * @param array<string, string> $map
 * @return array<string, string>
 */
function with_escaped(array $map): array
{
    foreach ($map as $from => $to) {
        $map[str_replace('/', '\/', $from)] = str_replace('/', '\/', $to);
    }
    return $map;
}

/** Location из ответа апстрима → адрес на зеркале. Чужие адреса не трогаем. */
function map_location(string $location, string $origin, string $prefix): string
{
    if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
        return $prefix . $location;
    }
    foreach (['https://' . QR_HOST => $origin] + cbr_map($origin) as $from => $to) {
        if (!str_starts_with($location, $from)) {
            continue;
        }
        $rest = substr($location, strlen($from));
        if ($rest === '' || str_contains('/?#', $rest[0])) {
            return $to . $rest;
        }
    }
    return $location;
}

/** Адрес на зеркале → исходный адрес апстрима (для Referer). Чужие адреса сводятся к корню qr.nspk.ru. */
function unmap_url(string $url, string $origin): string
{
    $fallback = 'https://' . QR_HOST . '/';
    if ($url !== $origin && !str_starts_with($url, $origin . '/')) {
        return $fallback;
    }
    $route = route(substr($url, strlen($origin)) ?: '/');
    return $route === null ? $fallback : 'https://' . $route[0] . $route[1];
}

/**
 * Публичный origin зеркала (схема://хост[:порт]), как его видит браузер.
 * MIRROR_ORIGIN важнее. Без него берём из запроса с учётом X-Forwarded-* от nginx на хосте.
 *
 * @param array<string, mixed> $server
 */
function detect_origin(array $server, ?string $configured): string
{
    if ($configured !== null && $configured !== '') {
        return rtrim($configured, '/');
    }
    $proto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($proto !== 'http' && $proto !== 'https') {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        $proto = ($https !== '' && $https !== 'off') ? 'https' : 'http';
    }
    $host = trim(explode(',', (string)($server['HTTP_X_FORWARDED_HOST'] ?? ''))[0]);
    if ($host === '') {
        $host = trim((string)($server['HTTP_HOST'] ?? ''));
    }
    if (!preg_match('/^(?:[A-Za-z0-9.-]+|\[[0-9A-Fa-f:.]+\])(?::\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    return $proto . '://' . $host;
}
