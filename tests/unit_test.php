<?php
// Запуск: docker compose exec mirror php /var/www/tests/unit_test.php
declare(strict_types=1);

putenv('MIRROR_SECRET=test-secret-0123456789abcdef');
putenv('RETURN_KEY=' . str_repeat('ab', 32));

require __DIR__ . '/../src/rewrite.php';
require __DIR__ . '/../src/upstream.php';
require __DIR__ . '/../src/banks.php';
require __DIR__ . '/../src/logo.php';
require __DIR__ . '/../src/pow.php';
require __DIR__ . '/../src/link.php';
require __DIR__ . '/../src/ret.php';
require __DIR__ . '/../src/page.php';

use function NspkMirror\amount_parts;
use function NspkMirror\open_return_token;
use function NspkMirror\return_info;
use function NspkMirror\return_key;
use function NspkMirror\seal_return_token;
use function NspkMirror\bank_link;
use function NspkMirror\clean_query;
use function NspkMirror\initial;
use function NspkMirror\parse_members_v1;
use function NspkMirror\parse_members_v2;
use function NspkMirror\qrc_id;
use function NspkMirror\render_page;
use function NspkMirror\resize_png;
use function NspkMirror\detect_origin;
use function NspkMirror\expires_in;
use function NspkMirror\format_amount;
use function NspkMirror\link_sig;
use function NspkMirror\link_state;
use function NspkMirror\merchant_allowed;
use function NspkMirror\public_link_info;
use function NspkMirror\render_state;
use function NspkMirror\parse_api_config;
use function NspkMirror\parse_link_response;
use function NspkMirror\pow_hash;
use function NspkMirror\pow_headers;
use function NspkMirror\siphash24;
use const NspkMirror\POW_DEFAULTS;
use function NspkMirror\map_location;
use function NspkMirror\rewrite_body;
use function NspkMirror\rewrite_json;
use function NspkMirror\route;
use function NspkMirror\unmap_url;

$fails = 0;

function check(string $name, mixed $got, mixed $want): void
{
    global $fails;
    if ($got === $want) {
        echo "ok   $name\n";
        return;
    }
    $fails++;
    echo "FAIL $name\n  got:  " . var_export($got, true) . "\n  want: " . var_export($want, true) . "\n";
}

$o = 'https://sbp.example.com';

// Хост диплинка в банк берётся из литерала px="https://qr.nspk.ru" (без слэша).
// Приложения банков принимают только qr.nspk.ru, поэтому эти места не трогаем.
check('px literal kept', rewrite_body('var lx="qr",px="https://qr.nspk.ru",mx="proxyapp/c2bmembers.json"', $o),
    'var lx="qr",px="https://qr.nspk.ru",mx="proxyapp/c2bmembers.json"');
check('wgUrl default kept', rewrite_body('wgUrl:t.wgUrl||"https://qr.nspk.ru"}', $o), 'wgUrl:t.wgUrl||"https://qr.nspk.ru"}');
check('bank deep link kept', rewrite_body('bank100000000111://qr.nspk.ru/BD1?type=02', $o), 'bank100000000111://qr.nspk.ru/BD1?type=02');

// База статики, скрипт и логотипы переезжают на зеркало.
check('static base', rewrite_body('return"https://qr.nspk.ru/"}', $o), 'return"https://sbp.example.com/"}');
check('qr-code-styling', rewrite_body('r.src="https://qr.nspk.ru/static/qr-code-styling.js"', $o),
    'r.src="https://sbp.example.com/static/qr-code-styling.js"');
check('logo in json', rewrite_body('"logoURL": "https://qr.nspk.ru/proxyapp/logo/bank100000000111.png"', $o),
    '"logoURL": "https://sbp.example.com/proxyapp/logo/bank100000000111.png"');
check('escaped json', rewrite_body('"logoURL":"https:\/\/qr.nspk.ru\/proxyapp\/logo\/x.png"', $o),
    '"logoURL":"https:\/\/sbp.example.com\/proxyapp\/logo\/x.png"');

// API-хосты cbrpay.ru уходят под /_cbr/<имя>.
check('api baseURL', rewrite_body('"baseURL": "https://upc.cbrpay.ru/v1/payment-link"', $o),
    '"baseURL": "https://sbp.example.com/_cbr/upc/v1/payment-link"');
check('cert check', rewrite_body('"checkUrl": "https://crt.cbrpay.ru/check"', $o), '"checkUrl": "https://sbp.example.com/_cbr/crt/check"');
check('widget template', rewrite_body('return`https://widget.cbrpay.ru/v2/${r}`', $o), 'return`https://sbp.example.com/_cbr/widget/v2/${r}`');
check('preconnect bare host', rewrite_body('<link rel="preconnect" href="https://upc.cbrpay.ru">', $o),
    '<link rel="preconnect" href="https://sbp.example.com/_cbr/upc">');
check('foreign hosts kept', rewrite_body('https://mc.yandex.ru/watch/1 https://sbp.nspk.ru/blog', $o), 'https://mc.yandex.ru/watch/1 https://sbp.nspk.ru/blog');

// JSON (статика НСПК и ответы API cbrpay.ru): только каталоги статики и хосты cbrpay.
// Шаблоны ссылок в банки (url у members) остаются как есть, включая возможные universal link на qr.nspk.ru.
check('json member logo', rewrite_json('"logo": "https://qr.nspk.ru/proxyapp/logo/bank100000000111.png"', $o),
    '"logo": "https://sbp.example.com/proxyapp/logo/bank100000000111.png"');
check('json uniqr icon', rewrite_json('"logoURL": "https://qr.nspk.ru/proxyapp/uniqr/bnpl.svg"', $o),
    '"logoURL": "https://sbp.example.com/proxyapp/uniqr/bnpl.svg"');
check('json escaped logo', rewrite_json('"logo":"https:\/\/qr.nspk.ru\/proxyapp\/logo\/x.png"', $o),
    '"logo":"https:\/\/sbp.example.com\/proxyapp\/logo\/x.png"');
check('json deep link template kept', rewrite_json('"url": "bank100000000111://qr.nspk.ru/{QRC_ID}{QUERY_STRING}"', $o),
    '"url": "bank100000000111://qr.nspk.ru/{QRC_ID}{QUERY_STRING}"');
check('json universal link kept', rewrite_json('"url": "https://qr.nspk.ru/{QRC_ID}?paymentServiceId=PS0000000002"', $o),
    '"url": "https://qr.nspk.ru/{QRC_ID}?paymentServiceId=PS0000000002"');
check('json api base', rewrite_json('"baseURL": "https://upc.cbrpay.ru/v1/payment-link"', $o),
    '"baseURL": "https://sbp.example.com/_cbr/upc/v1/payment-link"');
check('json web client kept', rewrite_json('"url": "https://www.tbank-online.com/mybank/payments/qr-pay/{QRC_ID}"', $o),
    '"url": "https://www.tbank-online.com/mybank/payments/qr-pay/{QRC_ID}"');

// Маршрутизация.
check('route qr', route('/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02'), ['qr.nspk.ru', '/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02', '']);
check('route root', route('/'), ['qr.nspk.ru', '/', '']);
check('route upc', route('/_cbr/upc/v1/payment-link'), ['upc.cbrpay.ru', '/v1/payment-link', '/_cbr/upc']);
check('route crt with query', route('/_cbr/crt?x=1'), ['crt.cbrpay.ru', '/?x=1', '/_cbr/crt']);
check('route crt bare', route('/_cbr/crt'), ['crt.cbrpay.ru', '/', '/_cbr/crt']);
check('route unknown upstream', route('/_cbr/evil.example/x'), null);
check('route localhost /qr/', route('/qr/proxyapp/apiConfig.json'), ['qr.nspk.ru', '/proxyapp/apiConfig.json', '']);

// Location и Referer.
check('location absolute', map_location('https://qr.nspk.ru/AS1?x=1', $o, ''), 'https://sbp.example.com/AS1?x=1');
check('location bare host', map_location('https://qr.nspk.ru', $o, ''), 'https://sbp.example.com');
check('location cbr absolute', map_location('https://upc.cbrpay.ru/v1/x', $o, ''), 'https://sbp.example.com/_cbr/upc/v1/x');
check('location relative cbr', map_location('/v1/x', $o, '/_cbr/upc'), '/_cbr/upc/v1/x');
check('location foreign', map_location('https://example.org/', $o, ''), 'https://example.org/');
check('location lookalike host', map_location('https://qr.nspk.ru.example.org/', $o, ''), 'https://qr.nspk.ru.example.org/');
check('referer page', unmap_url('https://sbp.example.com/BD1?type=02', $o), 'https://qr.nspk.ru/BD1?type=02');
check('referer cbr', unmap_url('https://sbp.example.com/_cbr/upc/v1/x', $o), 'https://upc.cbrpay.ru/v1/x');
check('referer foreign', unmap_url('https://example.org/x', $o), 'https://qr.nspk.ru/');

// Origin зеркала.
check('origin from proxy headers', detect_origin(['HTTP_HOST' => 'sbp.example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'], null), 'https://sbp.example.com');
check('origin from env', detect_origin(['HTTP_HOST' => 'other.example'], 'https://sbp.example.com/'), 'https://sbp.example.com');
check('origin local tls', detect_origin(['HTTP_HOST' => '127.0.0.1:8443', 'HTTPS' => 'on'], null), 'https://127.0.0.1:8443');
check('origin bad host', detect_origin(['HTTP_HOST' => 'a"b<c'], null), 'http://localhost');

// Страница НСПК целиком под /nspk/.
$id = 'BD10103IQ18O3ISP8RGAM6FN3AP1N0GH';
check('route nspk page', route('/nspk/' . $id . '?type=02'), ['qr.nspk.ru', '/' . $id . '?type=02', '/nspk']);
check('route nspk asset', route('/nspk/js/index.js'), ['qr.nspk.ru', '/js/index.js', '/nspk']);
check('referer nspk page', unmap_url('https://sbp.example.com/nspk/BD1?x=1', $o), 'https://qr.nspk.ru/BD1?x=1');
check('location relative nspk', map_location('/BD1', $o, '/nspk'), '/nspk/BD1');

// Запасной список банков: c2bmembers.json.
$banks = parse_members_v1((string)json_encode(['dictionary' => [
    ['bankName' => 'Сбербанк', 'logoURL' => 'https://qr.nspk.ru/proxyapp/logo/bank100000000111.png', 'schema' => 'bank100000000111', 'isWebClientActive' => false],
    ['bankName' => 'Т-Банк', 'logoURL' => 'https://qr.nspk.ru/proxyapp/logo/bank100000000004.png', 'schema' => 'bank100000000004',
        'isWebClientActive' => 'true', 'webClientUrl' => 'https://www.tbank-online.com/mybank/payments/qr-pay'],
    ['bankName' => 'Шаблон', 'logoURL' => 'https://cdn.example/logo.png', 'schema' => 'bank100000000999',
        'isWebClientActive' => true, 'webClientUrl' => 'https://bank.example/pay?id={qrcId}'],
    ['bankName' => 'Выключен', 'logoURL' => '', 'schema' => 'bank100000000998', 'isWebClientActive' => false, 'webClientUrl' => 'https://bank.example/off'],
    ['bankName' => 'Плохая схема', 'logoURL' => '', 'schema' => 'javascript'],
    ['bankName' => 'Плохой веб', 'logoURL' => '', 'schema' => 'bank100000000997', 'isWebClientActive' => true, 'webClientUrl' => 'javascript:alert(1)'],
    ['bankName' => 'С портом', 'logoURL' => '', 'schema' => 'bank100000000996', 'isWebClientActive' => true, 'webClientUrl' => 'https://ib.bankrsi.ru:5555/sbp'],
]], JSON_UNESCAPED_UNICODE));
check('v1 skip bad schema', count($banks), 6);
check('v1 logo file', $banks[0]['logo'], 'bank100000000111.png');
check('v1 app template', $banks[0]['template'], 'bank100000000111://qr.nspk.ru/{QRC_ID}{QUERY_STRING}');
check('v1 web "true"', $banks[1]['template'], 'https://www.tbank-online.com/mybank/payments/qr-pay/{QRC_ID}{QUERY_STRING}');
check('v1 web {qrcId}', $banks[2]['template'], 'https://bank.example/pay?id={QRC_ID}');
check('v1 foreign logo dropped', $banks[2]['logo'], '');
check('v1 inactive web → app', $banks[3]['template'], 'bank100000000998://qr.nspk.ru/{QRC_ID}{QUERY_STRING}');
check('v1 javascript web → app', $banks[4]['template'], 'bank100000000997://qr.nspk.ru/{QRC_ID}{QUERY_STRING}');
check('v1 web with port', $banks[5]['template'], 'https://ib.bankrsi.ru:5555/sbp/{QRC_ID}{QUERY_STRING}');
check('snapshot parses', count(parse_members_v1((string)file_get_contents(__DIR__ . '/../data/c2bmembers.json'))) > 150, true);

// Основной список банков: v2 API, тот же, что у страницы НСПК.
$v2 = parse_members_v2((string)json_encode(['members' => [
    ['id' => '100000000005', 'logo' => 'https://qr.nspk.ru/proxyapp/logo/bank100000000005.png', 'name' => 'Банк ВТБ',
        'url' => 'https://online.vneshtbank.ru/i/paymentSbp/{QRC_ID}{QUERY_STRING}'],
    ['id' => '100000000111', 'logo' => 'https://qr.nspk.ru/proxyapp/logo/bank100000000111.png', 'name' => 'Сбербанк',
        'url' => 'bank100000000111://qr.nspk.ru/{QRC_ID}{QUERY_STRING}'],
    ['id' => '100000000001', 'logo' => '', 'name' => 'Скрипт', 'url' => 'javascript:alert(1)//{QRC_ID}'],
    ['id' => '100000000002', 'logo' => '', 'name' => 'Чужой хост', 'url' => 'bank100000000002://evil.example/{QRC_ID}'],
    ['id' => '100000000003', 'logo' => '', 'name' => 'Без QRC_ID', 'url' => 'https://bank.example/pay'],
    ['id' => '12', 'logo' => '', 'name' => 'Плохой id', 'url' => 'bank100000000012://qr.nspk.ru/{QRC_ID}'],
]], JSON_UNESCAPED_UNICODE));
check('v2 keeps only safe links', array_column($v2, 'name'), ['Банк ВТБ', 'Сбербанк']);
check('v2 vtb logo', $v2[0]['logo'], 'bank100000000005.png');
check('v2 schema from id', $v2[1]['schema'], 'bank100000000111');

// Ссылки в банки: как у страницы НСПК.
check('link app', bank_link($banks[0], $id, '?type=02&sum=15630'), "bank100000000111://qr.nspk.ru/$id?type=02&sum=15630");
check('link web', bank_link($banks[1], $id, '?type=02'), "https://www.tbank-online.com/mybank/payments/qr-pay/$id?type=02");
check('link {qrcId} drops query', bank_link($banks[2], $id, '?type=02'), "https://bank.example/pay?id=$id");
check('link v2 web', bank_link($v2[0], $id, ''), "https://online.vneshtbank.ru/i/paymentSbp/$id");
check('link v1 trailing slash', bank_link(parse_members_v1((string)json_encode(['dictionary' => [
    ['bankName' => 'Б', 'schema' => 'bank100000000001', 'isWebClientActive' => true, 'webClientUrl' => 'https://b.example/qr/'],
]]))[0], $id, ''), "https://b.example/qr/$id");

check('qrc id', qrc_id('/' . $id), $id);
check('qrc id short', qrc_id('/BD1'), null);
check('qrc id nested', qrc_id('/nspk/' . $id), null);
check('query keeps order', clean_query('?type=02&bank=100000000111&sum=15630&cur=RUB&crc=AB75'), '?type=02&bank=100000000111&sum=15630&cur=RUB&crc=AB75');
check('query drops wg_', clean_query('?wg_embedded=1&type=02&WG_theme=dark'), '?type=02');
check('query empty', clean_query(''), '');
check('amount kopecks', amount_parts('?type=02&sum=15630&cur=RUB'), ['156,30', '₽']);
check('amount whole', amount_parts('?sum=150000'), ["1\u{202F}500", '₽']);
check('amount other currency', amount_parts('?sum=100&cur=USD'), null);
check('amount missing', amount_parts('?type=02'), null);
check('initial strips "Банк"', initial('Банк ВТБ'), 'В');
check('initial plain', initial('АЛЬФА-БАНК'), 'А');

// Страница: ссылки и экранирование.
$html = render_page([
    'qrcId' => $id,
    'banks' => [
        $banks[0] + ['link' => bank_link($banks[0], $id, '?type=02&sum=15630')],
        ['name' => '<b>Банк</b>', 'schema' => 'bank100000000002', 'logo' => '', 'link' => "bank100000000002://qr.nspk.ru/$id"],
    ],
    'amount' => amount_parts('?sum=15630'),
    'nspkUrl' => "https://qr.nspk.ru/$id",
    'pageUrl' => "https://sbp.example.com/$id",
    'fallbackUrl' => "/nspk/$id",
    'mobile' => true,
]);
check('page app link', str_contains($html, "href=\"bank100000000111://qr.nspk.ru/$id?type=02&amp;sum=15630\""), true);
check('page escapes name', str_contains($html, '&lt;b&gt;Банк&lt;/b&gt;') && !str_contains($html, '<b>Банк</b>'), true);
check('page qr data', str_contains($html, "data-app=\"https://qr.nspk.ru/$id\""), true);
check('page amount', str_contains($html, '156,30<span class="cur">₽</span>'), true);
check('page web banks dialog', str_contains($html, '<dialog class="sheet" id="web-banks" aria-labelledby="sheet-title">'), true);

// Ресайз логотипа (нужен GD, он есть в образе).
if (function_exists('imagecreatetruecolor')) {
    $im = imagecreatetruecolor(256, 128);
    ob_start();
    imagepng($im);
    $info = getimagesizefromstring((string)resize_png((string)ob_get_clean(), 40));
    check('logo resized to box', [$info[0] ?? 0, $info[1] ?? 0], [40, 40]);
} else {
    echo "skip logo resize: no GD\n";
}

// SipHash-2-4: эталонные векторы и строка в формате PoW, сверенная с JS страницы НСПК.
$hex = static fn(array $v): string => sprintf('%08x%08x', $v[0], $v[1]);
$refKey = (string)hex2bin('000102030405060708090a0b0c0d0e0f');
$powKey = (string)hex2bin(POW_DEFAULTS['key']);
check('siphash empty', $hex(siphash24('', $refKey)), '726fdb47dd0e0e31');
check('siphash 15 bytes', $hex(siphash24(implode(array_map('chr', range(0, 14))), $refKey)), 'a129ca6149be45e5');
check('siphash like NSPK js', $hex(siphash24('1;3f2a9c1e-7b5d-4e8a-9c0f-1a2b3c4d5e6f;1764025200000;0', $powKey)), '31abc03b8a3b6716');

// PoW: каждый хеш ниже сложности и посчитан от предыдущего, отметок времени на одну больше.
$guid = '3f2a9c1e-7b5d-4e8a-9c0f-1a2b3c4d5e6f';
$pow = [];
foreach (pow_headers(POW_DEFAULTS, $guid) as $line) {
    [$name, $value] = explode(': ', $line, 2);
    $pow[$name] = $value;
}
$stamps = explode(';', $pow['X-Token-Timestamp']);
$hashes = explode(';', $pow['X-Token-Pow']);
$nonces = explode(';', $pow['X-Token-Nonce']);
$chainOk = $hashes !== [''] && count($stamps) === count($hashes) + 1 && count($nonces) === count($hashes);
$prev = '0';
foreach ($hashes as $i => $value) {
    $chainOk = $chainOk && (string)pow_hash("{$nonces[$i]};$guid;{$stamps[$i]};$prev", $powKey) === $value && (int)$value < 2 ** 56;
    $prev = $value;
}
check('pow chain valid', $chainOk, true);
check('pow version and guid', [$pow['X-Token-Version'], $pow['X-Token-Guid']], ['prod_bypass_1', $guid]);

// apiConfig.json: адрес только на cbrpay.ru, параметры PoW в рамках.
$cfg = parse_api_config((string)json_encode([
    'baseURL' => 'https://upc.cbrpay.ru/v1/payment-link',
    'params' => ['difficulty' => 72057594037927940, 'key' => '11032FD175F1998436DE0C6F969325A6', 'maxPowCount' => 15, 'maxTimeThresholdMs' => 500, 'paramsVersion' => 'prod_bypass_1'],
    'errorMessages' => [['listOfCodes' => ['RQ05014'], 'message' => 'Код недействителен']],
], JSON_UNESCAPED_UNICODE));
check('config base', $cfg['baseURL'], 'https://upc.cbrpay.ru/v1/payment-link');
check('config error text', $cfg['errors']['RQ05014'], 'Код недействителен');
$bad = parse_api_config((string)json_encode([
    'baseURL' => 'http://169.254.169.254/latest',
    'params' => ['difficulty' => 1, 'key' => 'zz', 'maxPowCount' => 100000, 'maxTimeThresholdMs' => 999999, 'paramsVersion' => 'x'],
]));
check('config foreign base dropped', $bad['baseURL'], 'https://upc.cbrpay.ru/v1/payment-link');
check('config bad pow params → defaults', $bad['params'], POW_DEFAULTS);
check('config empty → defaults', parse_api_config('')['params'], POW_DEFAULTS);

// Ответы payment-link.
$errors = parse_api_config('')['errors'];
check('link ok', parse_link_response(200, '{"code":"RQ00000","message":"ok","data":{"brandName":"SHOP.EXAMPLE","amount":"15630"}}', $errors),
    ['status' => 'ok', 'amount' => '15630', 'amountLabel' => '156,30', 'merchant' => 'SHOP.EXAMPLE']);
check('link ok without amount', parse_link_response(200, '{"code":"RQ00000","data":{"brandName":"Магазин"}}', $errors), ['status' => 'ok', 'merchant' => 'Магазин']);
check('link expired', parse_link_response(400, '{"code":"RQ05014","message":"Значение uniQrId не найдено","data":null}', $errors)['expired'] ?? null, true);
check('link payment started', [parse_link_response(400, '{"code":"RQ05301"}', $errors)['status'] ?? null, parse_link_response(400, '{"code":"RQ05301"}', $errors)['expired'] ?? null], ['rejected', false]);
check('link pow rejected', parse_link_response(403, '', $errors), ['status' => 'error']);
check('format amount', [format_amount(15630), format_amount(150000), format_amount(5)], ['156,30', "1\u{202F}500", '0,05']);
check('link ok with ids', parse_link_response(200, '{"code":"RQ00000","data":{"amount":"100","merchantId":"MA0000001234","legalId":"LB0000005678"}}', $errors),
    ['status' => 'ok', 'amount' => '100', 'amountLabel' => '1', 'merchantId' => 'MA0000001234', 'legalId' => 'LB0000005678']);

// Параметры зеркала mx_* не уходят в банк, но остаются в адресе самой страницы.
check('query drops mx_', clean_query('?type=02&mx_exp=1764030000&sum=100'), '?type=02&sum=100');
check('query keeps mx_ for page', clean_query('?wg_theme=dark&mx_exp=1764030000', true), '?mx_exp=1764030000');
check('expires in', expires_in('?mx_exp=1764030300', 1764030000), 300);
check('expires past', expires_in('?mx_exp=1764029900', 1764030000), -100);
check('expires garbage', expires_in('?mx_exp=soon', 1764030000), null);
check('expires too far', expires_in('?mx_exp=1999999999', 1764030000), null);
check('expires missing', expires_in('?type=02', 1764030000), null);

// Антифрод: список мерчантов и экран вместо оплаты.
$okInfo = ['status' => 'ok', 'merchant' => 'SHOP.EXAMPLE', 'merchantId' => 'MA0000001234', 'legalId' => 'LB0000005678'];
check('merchant by id', merchant_allowed($okInfo, ['MA0000001234']), true);
check('merchant by legal id', merchant_allowed($okInfo, ['LB0000005678']), true);
check('merchant foreign', merchant_allowed($okInfo, ['MA0000000001']), false);
check('merchant no list', merchant_allowed($okInfo, []), true);
check('state ok', link_state($okInfo, ['MA0000001234'], false), null);
check('state foreign merchant → invalid', link_state($okInfo, ['MA0000000001'], false), ['kind' => 'invalid']);
check('state expired', link_state(['status' => 'rejected', 'code' => 'RQ05014', 'message' => 'm', 'expired' => true], [], false)['kind'] ?? null, 'expired');
check('state started', link_state(['status' => 'rejected', 'code' => 'RQ05301', 'message' => 'm', 'expired' => false], [], false)['kind'] ?? null, 'started');
check('state declined', link_state(['status' => 'rejected', 'code' => 'RQ05058', 'message' => 'm', 'expired' => false], [], false)['kind'] ?? null, 'error');
check('state nspk down, open', link_state(['status' => 'error'], ['MA0000001234'], false), null);
check('state nspk down, strict', link_state(['status' => 'error'], ['MA0000001234'], true), ['kind' => 'unavailable']);
check('public info hides ids', public_link_info($okInfo, null), ['status' => 'ok', 'merchant' => 'SHOP.EXAMPLE']);
check('public info rejected', public_link_info($okInfo, ['kind' => 'expired', 'code' => 'RQ05014', 'message' => 'm']),
    ['status' => 'rejected', 'state' => 'expired', 'code' => 'RQ05014', 'message' => 'm']);
check('link sig', link_sig('BD1'), substr(hash_hmac('sha256', 'BD1', 'test-secret-0123456789abcdef'), 0, 24));

$stateHtml = render_state(['kind' => 'expired', 'code' => 'RQ05014'], true);
check('state page title', str_contains($stateHtml, 'Время на оплату вышло'), true);
check('state page code', str_contains($stateHtml, 'Код НСПК RQ05014'), true);
check('state page marked', str_contains($stateHtml, 'class="is-mobile is-state"'), true);
check('state page without banks and qr', str_contains($stateHtml, 'bank1000') || str_contains($stateHtml, 'id="qr"'), false);
check('page has state templates', str_contains($html, '<template id="state-expired">') && str_contains($html, '<template id="state-invalid">'), true);

// Неизвестный код НСПК не теряется: он идёт в ответ и даёт экран «Оплатить не получится».
check('link error keeps code', parse_link_response(400, '{"code":"RQ09999"}', $errors), ['status' => 'error', 'code' => 'RQ09999']);
check('state unknown code → error', link_state(['status' => 'error', 'code' => 'RQ09999'], [], false)['kind'] ?? null, 'error');

// Токен возврата mx_r: AES-256-GCM, привязан к QRC_ID.
$rk = (string)return_key();
$retData = ['ok' => 'https://shop.example/ok?o=1', 'fail' => 'https://shop.example/fail', 'shop' => 'https://shop.example', 'avatar' => 'https://shop.example/logo.png'];
$tok = seal_return_token($retData, $id, $rk);
check('return key from hex', strlen($rk), 32);
check('token roundtrip', open_return_token($tok, $id, $rk), $retData);
check('token bound to its QR', open_return_token($tok, 'BD20000000000000000000000000000X', $rk), null);
check('token tampered', open_return_token(substr($tok, 0, -2) . (substr($tok, -2) === 'AA' ? 'AB' : 'AA'), $id, $rk), null);
check('token only fail', open_return_token(seal_return_token(['fail' => 'https://shop.example/fail'], $id, $rk), $id, $rk),
    ['ok' => '', 'fail' => 'https://shop.example/fail', 'shop' => '', 'avatar' => '']);
check('token javascript: rejected', open_return_token(seal_return_token(['ok' => 'javascript:alert(1)'], $id, $rk), $id, $rk), null);
check('token garbage', open_return_token('not*a*token', $id, $rk), null);
check('return info from query', return_info('?mx_exp=1&mx_r=' . $tok, $id)['shop'] ?? null, 'https://shop.example');
check('return info absent', return_info('?type=02', $id), null);

$retPage = render_page([
    'qrcId' => $id,
    'banks' => [$banks[0] + ['link' => bank_link($banks[0], $id, '')]],
    'amount' => null,
    'nspkUrl' => "https://qr.nspk.ru/$id",
    'pageUrl' => "https://sbp.example.com/$id",
    'fallbackUrl' => "/nspk/$id",
    'mobile' => false,
    'ret' => return_info('?mx_r=' . $tok, $id),
]);
check('page return flags only', str_contains($retPage, 'data-ret-ok="1"') && str_contains($retPage, 'data-ret-avatar="1"'), true);
check('page hides return urls', str_contains($retPage, 'shop.example/ok') || str_contains($retPage, 'shop.example/fail') || str_contains($retPage, 'logo.png'), false);
check('page avatar via proxy', str_contains($retPage, '/a/' . $id . '?t='), true);
check('page shop link with host as name', str_contains($retPage, 'href="https://shop.example" target="_blank" rel="noopener">shop.example</a>'), true);
check('page paid template', str_contains($retPage, '<template id="state-paid">'), true);

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
