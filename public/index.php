<?php
// Зеркало оплаты СБП.
//   /<QRC_ID>                 своя страница выбора банка с диплинками и QR-кодом
//   /logo/<размер>/<файл>     логотипы банков под размер плитки
//   /nspk/<QRC_ID>            страница НСПК целиком, запасной вариант
//   остальное                 прозрачный прокси qr.nspk.ru и API cbrpay.ru для неё
// Сертификаты НСПК и cbrpay.ru выпущены Russian Trusted Root CA, которому браузеры не доверяют.
declare(strict_types=1);

namespace NspkMirror;

require __DIR__ . '/../src/rewrite.php';
require __DIR__ . '/../src/upstream.php';
require __DIR__ . '/../src/banks.php';
require __DIR__ . '/../src/logo.php';
require __DIR__ . '/../src/page.php';

const MAX_REQUEST_BODY = 65536;
const STATIC_TTL = 86400;

// Статика НСПК, которую можно держать в дисковом кэше: логотипы, иконки, шрифты, картинки.
const STATIC_PREFIXES = ['/proxyapp/logo/', '/proxyapp/uniqr/', '/static/', '/woff2/', '/favicon.ico'];

// Не передаём апстриму: hop-by-hop, сведения о прокси перед нами, сжатие (его ведёт curl).
const DROP_REQUEST = [
    'host', 'connection', 'keep-alive', 'proxy-connection', 'proxy-authorization', 'te', 'trailer',
    'transfer-encoding', 'upgrade', 'content-length', 'accept-encoding', 'expect', 'forwarded', 'via',
    'x-real-ip', 'true-client-ip',
];

// Не отдаём клиенту: hop-by-hop, сжатие (тело уже распаковано и переписано), политики апстрима,
// вредные на чужом домене (HSTS с includeSubDomains, Alt-Svc, NEL, CSP со списком его хостов), служебное CDN.
const DROP_RESPONSE = [
    'connection', 'keep-alive', 'transfer-encoding', 'content-encoding', 'content-length',
    'strict-transport-security', 'alt-svc', 'report-to', 'nel', 'server',
    'content-security-policy', 'content-security-policy-report-only',
    'x-cdn-request-id', 'x-cdn-edge-id', 'x-cdn-edge-cache',
];

const PAGE_CSP = "default-src 'none'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; "
    . "base-uri 'none'; form-action 'none'";

function plain(int $status, string $text): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $text;
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
    // JSON с любого апстрима: логотипы и адреса API. HTML и JS страницы НСПК: ещё и база статики qr.nspk.ru.
    if (preg_match('#^application/(?:[a-z.+-]*\+)?json#', $type)) {
        $body = rewrite_json($body, $origin);
    } elseif ($host === QR_HOST && preg_match('#^(?:text/|application/(?:javascript|x-javascript))#', $type)) {
        $body = rewrite_body($body, $origin);
    }
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$qpos = strpos($uri, '?');
$path = $qpos === false ? $uri : substr($uri, 0, $qpos);
$query = $qpos === false ? '' : substr($uri, $qpos);
$origin = detect_origin($_SERVER, getenv('MIRROR_ORIGIN') ?: null);
$readOnly = $method === 'GET' || $method === 'HEAD';

if ($path === '/_mirror/health') {
    plain(200, "ok\n");
}
if (!in_array($method, ['GET', 'HEAD', 'POST', 'OPTIONS'], true)) {
    header('Allow: GET, HEAD, POST, OPTIONS');
    plain(405, "Method Not Allowed\n");
}
// Корень зеркалу не нужен. Service worker НСПК на корне перехватывал бы навигацию на /<QRC_ID>
// и подсовывал свою страницу, поэтому его отдаём только под /nspk/.
if ($path === '/' || $path === '/serviceWorker.js') {
    plain(404, "Not Found\n");
}

// Своя страница выбора банка.
$qrcId = qrc_id($path);
if ($qrcId !== null && $readOnly) {
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
    $html = render_page([
        'banks' => $banks,
        'amount' => amount_parts($clean),
        'nspkUrl' => 'https://' . QR_HOST . '/' . $qrcId . $clean,
        'pageUrl' => $origin . '/' . $qrcId . $clean,
        'fallbackUrl' => '/nspk/' . $qrcId . $clean,
        'mobile' => is_mobile_ua((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
    ]);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Content-Security-Policy: ' . PAGE_CSP);
    header('Referrer-Policy: no-referrer');
    header('X-Content-Type-Options: nosniff');
    header('Content-Length: ' . strlen($html));
    if ($method === 'GET') {
        echo $html;
    }
    exit;
}

// Логотипы банков под размер плитки.
if (preg_match('#^/logo/(\d{2,3})/(bank\d{12}\.png)$#', $path, $m) && $readOnly) {
    $res = logo((int)$m[1], $m[2]);
    if ($res['status'] !== 200) {
        plain($res['status'] === 404 ? 404 : 502, "Logo unavailable\n");
    }
    header('Content-Type: ' . $res['type']);
    header('Cache-Control: public, max-age=604800');
    header('X-Mirror-Cache: ' . $res['cache']);
    header('Content-Length: ' . strlen($res['body']));
    if ($method === 'GET') {
        echo $res['body'];
    }
    exit;
}

// Дальше прозрачный прокси: страница НСПК под /nspk/, её статика и API cbrpay.ru.
$route = route($uri);
if ($route === null) {
    plain(404, "Not Found\n");
}
[$host, $upPath, $prefix] = $route;

$upPathOnly = strtok($upPath, '?') ?: '/';
foreach (STATIC_PREFIXES as $static) {
    if ($method === 'GET' && $host === QR_HOST && str_starts_with($upPathOnly, $static)) {
        $res = cached_get('https://' . QR_HOST . $upPath, STATIC_TTL);
        $headers = [['content-type', $res['type']], ['x-mirror-cache', $res['cache']]];
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
