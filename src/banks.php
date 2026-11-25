<?php
declare(strict_types=1);

namespace NspkMirror;

// Основной источник — тот же API, что у страницы НСПК: готовые шаблоны ссылок и актуальные логотипы.
const MEMBERS_V2_URL = 'https://widget.cbrpay.ru/v2/sbp/members/qr';
const MEMBERS_V2_CLIENT = '000000000000'; // X-CLIENT-ID из js/index.js страницы НСПК
// Запасной — статичный c2bmembers.json (у ВТБ там устаревший id без логотипа), последний рубеж — его снимок.
const MEMBERS_V1_URL = 'https://qr.nspk.ru/proxyapp/c2bmembers.json';
const MEMBERS_SNAPSHOT = __DIR__ . '/../data/c2bmembers.json';
const MEMBERS_TTL = 600;

/**
 * Банки в порядке НСПК (популярные первыми). template — ссылка с плейсхолдерами {QRC_ID} и {QUERY_STRING}.
 *
 * @return list<array{name: string, schema: string, logo: string, template: string}>
 */
function load_banks(): array
{
    $v2 = cached_get(MEMBERS_V2_URL, MEMBERS_TTL, [
        'X-CLIENT-ID: ' . MEMBERS_V2_CLIENT,
        'Accept: application/json',
        'Origin: https://' . QR_HOST,
        'Referer: https://' . QR_HOST . '/',
    ], 5);
    $banks = $v2['status'] === 200 ? parse_members_v2($v2['body']) : [];
    if ($banks === []) {
        $v1 = cached_get(MEMBERS_V1_URL, MEMBERS_TTL, [], 5);
        $banks = $v1['status'] === 200 ? parse_members_v1($v1['body']) : [];
    }
    if ($banks === []) {
        $banks = parse_members_v1((string)@file_get_contents(MEMBERS_SNAPSHOT));
    }
    return $banks;
}

/**
 * Ответ widget.cbrpay.ru/v2/sbp/members/qr: {"members": [{id, logo, name, url}]}.
 *
 * @return list<array{name: string, schema: string, logo: string, template: string}>
 */
function parse_members_v2(string $json): array
{
    $data = json_decode($json, true);
    $out = [];
    foreach ((is_array($data) ? $data['members'] ?? [] : []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $id = (string)($m['id'] ?? '');
        $name = trim((string)($m['name'] ?? ''));
        $template = (string)($m['url'] ?? '');
        if (!preg_match('/^\d{12}$/', $id) || $name === '' || !valid_template($template)) {
            continue;
        }
        $out[] = ['name' => $name, 'schema' => 'bank' . $id, 'logo' => logo_file((string)($m['logo'] ?? '')), 'template' => $template];
    }
    return $out;
}

/**
 * c2bmembers.json: {"dictionary": [{bankName, logoURL, schema, webClientUrl, isWebClientActive}]}.
 * Шаблон по логике страницы НСПК: веб-клиент, если он у банка включён, иначе схема приложения с хостом qr.nspk.ru.
 *
 * @return list<array{name: string, schema: string, logo: string, template: string}>
 */
function parse_members_v1(string $json): array
{
    $data = json_decode($json, true);
    $out = [];
    foreach ((is_array($data) ? $data['dictionary'] ?? [] : []) as $m) {
        if (!is_array($m)) {
            continue;
        }
        $schema = (string)($m['schema'] ?? '');
        $name = trim((string)($m['bankName'] ?? ''));
        if (!preg_match('/^bank\d{12}$/', $schema) || $name === '') {
            continue;
        }
        $app = $schema . '://' . QR_HOST . '/{QRC_ID}{QUERY_STRING}';
        $active = ($m['isWebClientActive'] ?? false) === true || ($m['isWebClientActive'] ?? '') === 'true';
        $web = $active ? (string)($m['webClientUrl'] ?? '') : '';
        if ($web !== '') {
            $web = str_contains($web, '{qrcId}')
                ? str_replace('{qrcId}', '{QRC_ID}', $web)
                : $web . (str_ends_with($web, '/') ? '' : '/') . '{QRC_ID}{QUERY_STRING}';
        }
        $out[] = [
            'name' => $name,
            'schema' => $schema,
            'logo' => logo_file((string)($m['logoURL'] ?? '')),
            'template' => $web !== '' && valid_template($web) ? $web : $app,
        ];
    }
    return $out;
}

/**
 * Апстрим без проверки сертификата, поэтому в href пускаем только два вида ссылок:
 * схему приложения банка на qr.nspk.ru и https-адрес веб-клиента. В обоих должен быть {QRC_ID}.
 */
function valid_template(string $t): bool
{
    return str_contains($t, '{QRC_ID}') && (
        preg_match('#^bank\d{12}://qr\.nspk\.ru/\{QRC_ID\}[^\s"<>]*$#', $t) === 1
        || preg_match('#^https://[A-Za-z0-9.-]+(?::\d{1,5})?/[^\s"<>]*$#', $t) === 1
    );
}

/** Имя файла логотипа из адреса НСПК. Сам файл всегда берём с qr.nspk.ru/proxyapp/logo/. */
function logo_file(string $url): string
{
    $file = basename((string)parse_url($url, PHP_URL_PATH));
    return preg_match('/^bank\d{12}\.png$/', $file) ? $file : '';
}

/** @param array{template: string} $bank */
function bank_link(array $bank, string $qrcId, string $query): string
{
    return strtr($bank['template'], ['{QRC_ID}' => $qrcId, '{QUERY_STRING}' => $query]);
}

/** QRC_ID из пути вида /<32 символа>, иначе null. */
function qrc_id(string $path): ?string
{
    return preg_match('#^/([A-Za-z0-9]{32})$#', $path, $m) ? $m[1] : null;
}

/**
 * Query ссылки без служебных параметров виджета НСПК (wg_*), в исходном виде и порядке:
 * параметры статических QR (sum, cur, crc) должны дойти до приложения банка без изменений.
 */
function clean_query(string $query): string
{
    $parts = array_filter(
        explode('&', ltrim($query, '?')),
        static fn(string $p): bool => $p !== '' && !str_starts_with(strtolower($p), 'wg_'),
    );
    return $parts ? '?' . implode('&', $parts) : '';
}

/**
 * Сумма из query статического QR: sum в копейках, cur=RUB или без cur.
 *
 * @return array{0: string, 1: string}|null [рубли с копейками, знак валюты]
 */
function amount_parts(string $query): ?array
{
    parse_str(ltrim($query, '?'), $q);
    $sum = $q['sum'] ?? null;
    $cur = strtoupper((string)($q['cur'] ?? 'RUB'));
    if (!is_string($sum) || !preg_match('/^\d{1,12}$/', $sum) || $cur !== 'RUB' || (int)$sum === 0) {
        return null;
    }
    return [format_amount((int)$sum), '₽'];
}

/** Копейки → «1 500» или «156,30», без знака валюты. */
function format_amount(int $kop): string
{
    $rub = number_format(intdiv($kop, 100), 0, ',', "\u{202F}");
    return $kop % 100 ? $rub . ',' . sprintf('%02d', $kop % 100) : $rub;
}
