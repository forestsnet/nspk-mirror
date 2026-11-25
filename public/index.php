<?php
declare(strict_types=1);

namespace NspkMirror;

require __DIR__ . '/../src/rewrite.php';
require __DIR__ . '/../src/upstream.php';
require __DIR__ . '/../src/banks.php';
require __DIR__ . '/../src/logo.php';
require __DIR__ . '/../src/pow.php';
require __DIR__ . '/../src/link.php';
require __DIR__ . '/../src/ret.php';
require __DIR__ . '/../src/page.php';

const MAX_REQUEST_BODY = 65536;
const STATIC_TTL = 86400;
// Проверка ссылки перед отрисовкой страницы: дольше не ждём, страница досчитает сама.
const RENDER_CHECK_TIMEOUT = 3;

// Пути, которые уходят в прокси НСПК. Остальное, кроме своих маршрутов, — экран «Ссылка недействительна».
const PROXY_PREFIXES = ['/nspk/', '/_cbr/', '/qr/', '/proxyapp/', '/static/'];

// Статика НСПК, которая отдаётся из дискового кэша.
const STATIC_PREFIXES = ['/proxyapp/logo/', '/proxyapp/uniqr/', '/static/', '/woff2/', '/favicon.ico'];

// Не передаём апстриму: hop-by-hop, сведения о прокси перед нами, сжатие (его ведёт curl).
const DROP_REQUEST = [
    'host', 'connection', 'keep-alive', 'proxy-connection', 'proxy-authorization', 'te', 'trailer',
    'transfer-encoding', 'upgrade', 'content-length', 'accept-encoding', 'expect', 'forwarded', 'via',
    'x-real-ip', 'true-client-ip',
];

// Не отдаём клиенту: hop-by-hop, сжатие (тело уже распаковано), служебное CDN и политики апстрима,
// вредные на чужом домене (HSTS с includeSubDomains, Alt-Svc, NEL, CSP со списком его хостов).
const DROP_RESPONSE = [
    'connection', 'keep-alive', 'transfer-encoding', 'content-encoding', 'content-length',
    'strict-transport-security', 'alt-svc', 'report-to', 'nel', 'server',
    'content-security-policy', 'content-security-policy-report-only',
    'x-cdn-request-id', 'x-cdn-edge-id', 'x-cdn-edge-cache',
];

const PAGE_CSP = "default-src 'none'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; "
    . "connect-src 'self'; base-uri 'none'; form-action 'none'";

function plain(int $status, string $text): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text;
    exit;
}

function send_html(int $status, string $html, bool $headOnly): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Security-Policy: ' . PAGE_CSP);
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($html));
    if (!$headOnly) {
        echo $html;
    }
    exit;
}

function send_json(int $status, array $data): never
{
    $json = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($json));
    echo $json;
    exit;
}

/**
 * Отдаёт ответ апстрима: фильтрует заголовки, переписывает адреса в HTML, JS и JSON.
 *
 * @param list<array{0: string, 1: string}> $headers
 */
function send_upstream(int $status, array $headers, string $body, string $host, string $origin, string $prefix, bool $headOnly): never
{
    http_response_code($status);
    $type = '';
    foreach ($headers as [$name, $value]) {
        if (in_array($name, DROP_RESPONSE, true)) {
            continue;
        }
        if ($name === 'content-type') {
            $type = strtolower($value);
        } elseif ($name === 'location') {
            $value = map_location($value, $origin, $prefix);
        } elseif ($name === 'set-cookie') {
            $value = (string)preg_replace('/;\s*domain=[^;]*/i', '', $value);
        }
        header($name . ': ' . $value, $name !== 'set-cookie');
    }
    if ($headOnly || $status === 204 || $status === 304) {
        exit;
    }
    if (preg_match('#^application/(?:[a-z.+-]*\+)?json#', $type)) {
        $body = rewrite_json($body, $origin);
    } elseif ($host === QR_HOST && preg_match('#^(?:text/|application/(?:javascript|x-javascript))#', $type)) {
        $body = rewrite_body($body, $origin);
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/** Запрос пришёл со страницы самого зеркала. Браузеры ставят Sec-Fetch-Site, для старых смотрим Origin и Referer. */
function same_origin(array $server, string $origin): bool
{
    $site = strtolower((string)($server['HTTP_SEC_FETCH_SITE'] ?? ''));
    if ($site !== '') {
        return $site === 'same-origin';
    }
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $name) {
        $value = (string)($server[$name] ?? '');
        if ($value !== '') {
            return $value === $origin || str_starts_with($value, $origin . '/');
        }
    }
    return false;
}

/**
 * Проверка ссылки у НСПК и списка мерчантов перед тем, как показать оплату.
 *
 * @return array{0: array<string, mixed>, 1: array{kind: string, code?: string, message?: string}|null}
 */
function check_link(string $qrcId, string $ua): array
{
    $allowed = allowed_merchants();
    $info = link_info($qrcId, $ua, RENDER_CHECK_TIMEOUT);
    $state = link_state($info, $allowed, strict_mode());
    if ($state !== null && $state['kind'] === 'invalid' && $info['status'] === 'ok') {
        error_log(sprintf('nspk-mirror: blocked %s merchant=%s legal=%s brand=%s',
            $qrcId, $info['merchantId'] ?? '-', $info['legalId'] ?? '-', $info['merchant'] ?? '-'));
    }
    return [$info, $state];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$qpos = strpos($uri, '?');
$path = $qpos === false ? $uri : substr($uri, 0, $qpos);
$query = $qpos === false ? '' : substr($uri, $qpos);
$origin = detect_origin($_SERVER, getenv('MIRROR_ORIGIN') ?: null);
$readOnly = $method === 'GET' || $method === 'HEAD';
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$mobile = is_mobile_ua($ua);

if ($path === '/_mirror/health') {
    plain(200, "ok\n");
}
if (!in_array($method, ['GET', 'HEAD', 'POST', 'OPTIONS'], true)) {
    header('Allow: GET, HEAD, POST, OPTIONS');
    plain(405, "Method Not Allowed\n");
}

if (preg_match('#^/api/link/([A-Za-z0-9]{32})$#', $path, $m) && $method === 'GET') {
    parse_str(ltrim($query, '?'), $q);
    if (!hash_equals(link_sig($m[1]), (string)($q['s'] ?? ''))) {
        plain(403, "Forbidden\n");
    }
    $info = link_info($m[1], $ua);
    send_json(200, public_link_info($info, link_state($info, allowed_merchants(), false)));
}

// Возврат в магазин: адреса лежат только в зашифрованном mx_r, страница знает лишь, что они есть.
if (preg_match('#^/r/([A-Za-z0-9]{32})$#', $path, $m) && $readOnly) {
    parse_str(ltrim($query, '?'), $q);
    $key = return_key();
    $data = $key !== null ? open_return_token(is_string($q['t'] ?? null) ? $q['t'] : '', $m[1], $key) : null;
    $to = in_array($q['to'] ?? '', ['ok', 'fail', 'shop'], true) ? $q['to'] : 'fail';
    $url = $data === null ? '' : ($data[$to] !== '' ? $data[$to] : $data['shop']);
    if ($url === '') {
        send_html(404, render_state(['kind' => 'invalid'], $mobile), $method === 'HEAD');
    }
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $url, true, 303);
    exit;
}

if (preg_match('#^/a/([A-Za-z0-9]{32})$#', $path, $m) && $readOnly) {
    parse_str(ltrim($query, '?'), $q);
    $key = return_key();
    $data = $key !== null ? open_return_token(is_string($q['t'] ?? null) ? $q['t'] : '', $m[1], $key) : null;
    $res = ($data['avatar'] ?? '') !== '' ? avatar($data['avatar'], (int)($q['s'] ?? 24)) : ['status' => 404, 'body' => ''];
    if ($res['status'] !== 200) {
        plain(404, "Not Found\n");
    }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($res['body']));
    if ($method === 'GET') {
        echo $res['body'];
    }
    exit;
}

$qrcId = qrc_id($path);
if ($qrcId !== null && $readOnly) {
    $ret = return_info($query, $qrcId);
    $expiresIn = expires_in($query, time());
    if ($expiresIn !== null && $expiresIn <= 0) {
        send_html(410, render_state(['kind' => 'expired'], $mobile, ['qrcId' => $qrcId, 'ret' => $ret]), $method === 'HEAD');
    }
    [$info, $state] = check_link($qrcId, $ua);
    if ($state !== null) {
        // «Ссылка недействительна» без возврата: это и чужой мерчант. «Оплата уже идёт» продолжает следить за статусом.
        $ctx = match ($state['kind']) {
            'invalid' => [],
            'started' => ['qrcId' => $qrcId, 'ret' => $ret, 'sig' => link_sig($qrcId), 'started' => true],
            default => ['qrcId' => $qrcId, 'ret' => $ret],
        };
        send_html(STATES[$state['kind']]['http'], render_state($state, $mobile, $ctx), $method === 'HEAD');
    }

    $clean = clean_query($query);
    $banks = [];
    foreach (load_banks() as $bank) {
        $banks[] = $bank + ['link' => bank_link($bank, $qrcId, $clean)];
    }
    if ($banks === []) {
        error_log('nspk-mirror: bank list is empty, sending to the NSPK page');
        header('Location: /nspk/' . $qrcId . $clean, true, 302);
        exit;
    }
    send_html(200, render_page([
        'qrcId' => $qrcId,
        'banks' => $banks,
        'amount' => isset($info['amountLabel']) ? [$info['amountLabel'], '₽'] : amount_parts($clean),
        'merchant' => $info['merchant'] ?? null,
        'verified' => $info['status'] === 'ok',
        'sig' => link_sig($qrcId),
        'expiresIn' => $expiresIn,
        'ret' => $ret,
        'nspkUrl' => 'https://' . QR_HOST . '/' . $qrcId . $clean,
        'pageUrl' => $origin . '/' . $qrcId . clean_query($query, true),
        'fallbackUrl' => '/nspk/' . $qrcId . $clean,
        'mobile' => $mobile,
    ]), $method === 'HEAD');
}

if (preg_match('#^/logo/(\d{2,3})/(bank\d{12}\.png)$#', $path, $m) && $readOnly) {
    $res = logo((int)$m[1], $m[2]);
    if ($res['status'] !== 200) {
        plain($res['status'] === 404 ? 404 : 502, "Not Found\n");
    }
    header('Content-Type: ' . $res['type']);
    header('Cache-Control: public, max-age=604800');
    header('X-Cache: ' . $res['cache']);
    header('Content-Length: ' . strlen($res['body']));
    if ($method === 'GET') {
        echo $res['body'];
    }
    exit;
}

// Страница НСПК целиком проходит ту же проверку ссылки и мерчанта, что и своя.
if (preg_match('#^/nspk/([A-Za-z0-9]{32})$#', $path, $m) && $readOnly) {
    [, $state] = check_link($m[1], $ua);
    if ($state !== null) {
        send_html(STATES[$state['kind']]['http'], render_state($state, $mobile), $method === 'HEAD');
    }
}

$proxied = $path === '/favicon.ico';
foreach (PROXY_PREFIXES as $prefix) {
    $proxied = $proxied || str_starts_with($path, $prefix);
}
if (!$proxied) {
    if ($path === '/serviceWorker.js' || preg_match('#\.[a-z0-9]{1,5}$#i', $path)) {
        plain(404, "Not Found\n");
    }
    send_html(404, render_state(['kind' => 'invalid'], $mobile), $method === 'HEAD');
}

// API cbrpay.ru отвечает только страницам с этого же домена, иначе зеркало стало бы открытым прокси к НСПК.
if (str_starts_with($path, CBR_PREFIX) && !same_origin($_SERVER, $origin)) {
    plain(403, "Forbidden\n");
}

$route = route($uri);
if ($route === null) {
    plain(404, "Not Found\n");
}
[$host, $upPath, $prefix] = $route;

$upPathOnly = strtok($upPath, '?') ?: '/';
foreach (STATIC_PREFIXES as $static) {
    if ($method === 'GET' && $host === QR_HOST && str_starts_with($upPathOnly, $static)) {
        $res = cached_get('https://' . QR_HOST . $upPath, STATIC_TTL);
        $headers = [['content-type', $res['type']], ['x-cache', $res['cache']]];
        if ($res['status'] === 200) {
            $headers[] = ['cache-control', 'public, max-age=' . STATIC_TTL];
        }
        send_upstream($res['status'], $headers, $res['body'], $host, $origin, $prefix, false);
    }
}

$requestHeaders = [];
foreach (getallheaders() as $name => $value) {
    $lname = strtolower((string)$name);
    if (in_array($lname, DROP_REQUEST, true) || str_starts_with($lname, 'x-forwarded-') || str_starts_with($lname, 'cf-')) {
        continue;
    }
    // Для апстрима запрос выглядит так, будто его сделала оригинальная страница на qr.nspk.ru.
    if ($lname === 'origin') {
        $value = 'https://' . QR_HOST;
    } elseif ($lname === 'referer') {
        $value = unmap_url($value, $origin);
    }
    $requestHeaders[] = $name . ': ' . $value;
}

$body = null;
if ($method === 'POST') {
    $body = (string)file_get_contents('php://input', false, null, 0, MAX_REQUEST_BODY + 1);
    if (strlen($body) > MAX_REQUEST_BODY) {
        plain(413, "Payload Too Large\n");
    }
}

$res = upstream_request($method, 'https://' . $host . $upPath, $requestHeaders, $body);
if ($res['status'] === 0) {
    error_log(sprintf('nspk-mirror: %s https://%s%s: %s', $method, $host, $upPath, $res['error']));
    plain(502, "Сервис оплаты СБП временно недоступен. Попробуйте ещё раз через минуту.\n");
}
send_upstream($res['status'], $res['headers'], $res['body'], $host, $origin, $prefix, $method === 'HEAD');
