<?php
// Запуск: docker compose exec mirror php /var/www/tests/unit_test.php
declare(strict_types=1);

require __DIR__ . '/../src/rewrite.php';
require __DIR__ . '/../src/upstream.php';
require __DIR__ . '/../src/banks.php';
require __DIR__ . '/../src/logo.php';
require __DIR__ . '/../src/page.php';

use function NspkMirror\amount_parts;
use function NspkMirror\bank_link;
use function NspkMirror\clean_query;
use function NspkMirror\initial;
use function NspkMirror\parse_members_v1;
use function NspkMirror\parse_members_v2;
use function NspkMirror\qrc_id;
use function NspkMirror\render_page;
use function NspkMirror\resize_png;
use function NspkMirror\detect_origin;
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

echo $fails ? "\n$fails failed\n" : "\nall passed\n";
exit($fails ? 1 : 0);
