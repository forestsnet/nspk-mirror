<?php
declare(strict_types=1);

namespace NspkMirror;

const API_CONFIG_URL = 'https://qr.nspk.ru/proxyapp/apiConfig.json';
const PAYMENT_LINK_URL = 'https://upc.cbrpay.ru/v1/payment-link';
// Параметры из apiConfig.json на 25.11.2026, если конфиг не скачался или пришёл кривым.
const POW_DEFAULTS = [
    'key' => '11032FD175F1998436DE0C6F969325A6',
    'paramsVersion' => 'prod_bypass_1',
    'difficulty' => 72057594037927940,
    'maxTimeThresholdMs' => 500,
    'maxPowCount' => 15,
];
const LINK_ERRORS = [
    'RQ05014' => 'QR‑код недействителен. Попробуйте отсканировать его еще раз или запросите новый QR‑код.',
    'RQ05016' => 'QR‑код недействителен. Попробуйте отсканировать его еще раз или запросите новый QR‑код.',
    'RQ05027' => 'QR‑код недействителен. Попробуйте отсканировать его еще раз или запросите новый QR‑код.',
    'RQ05301' => 'По этому QR‑коду уже началась оплата, дождитесь ее завершения.',
    'RQ05022' => 'По этому QR‑коду уже началась оплата, дождитесь ее завершения.',
];
const EXPIRED_CODES = ['RQ05014', 'RQ05016', 'RQ05027'];
const STARTED_CODES = ['RQ05301', 'RQ05022'];
const LINK_ERROR_TTL = 10;
// Открытая страница перепроверяет ссылку раз в 30 секунд, кэш успеха не должен быть дольше.
const LINK_OK_TTL = 30;
const LINK_REJECTED_TTL = 60;

/**
 * Данные платежа по QRC_ID из payment-link API НСПК: сумма, получатель или причина отказа.
 * Успех кэшируется на 30 секунд, отказ на минуту, сетевые ошибки и 403 не кэшируются.
 *
 * @return array{status: string, amount?: string, amountLabel?: string, merchant?: string, merchantId?: string, legalId?: string, code?: string, message?: string, expired?: bool}
 */
function link_info(string $qrcId, string $userAgent, int $timeout = 6): array
{
    $key = "link/$qrcId";
    $copy = cache_read($key, LINK_OK_TTL);
    if ($copy !== null) {
        $cached = json_decode($copy['body'], true);
        if (is_array($cached) && (int)($cached['expires'] ?? 0) > time()) {
            unset($cached['expires']);
            return $cached;
        }
    }

    $res = cached_get(API_CONFIG_URL, MEMBERS_TTL, [], 3);
    $config = parse_api_config($res['status'] === 200 ? $res['body'] : '');
    $up = upstream_request('GET', $config['baseURL'] . '/' . $qrcId, array_merge([
        'Accept: application/json',
        'Origin: https://' . QR_HOST,
        'Referer: https://' . QR_HOST . '/',
        'User-Agent: ' . ($userAgent !== '' ? $userAgent : 'Mozilla/5.0'),
    ], pow_headers($config['params'])), null, $timeout);

    $info = parse_link_response($up['status'], $up['body'], $config['errors']);
    // Смена кода по QR в лог: так видно, как НСПК отражает оплату (RQ00000 → RQ05301 → …).
    $previous = $copy !== null ? json_decode($copy['body'], true) : null;
    if (is_array($previous) && link_code($previous) !== link_code($info)) {
        error_log(sprintf('nspk-mirror: link %s %s → %s', $qrcId, link_code($previous), link_code($info)));
    }
    if ($info['status'] === 'error') {
        error_log(sprintf('nspk-mirror: payment-link %s: HTTP %d %s', $qrcId, $up['status'], $up['error'] ?: substr($up['body'], 0, 200)));
    }
    // Ошибку держим недолго: при лежащем НСПК каждое открытие страницы иначе ждало бы таймаут.
    $ttl = ['ok' => LINK_OK_TTL, 'rejected' => LINK_REJECTED_TTL][$info['status']] ?? LINK_ERROR_TTL;
    cache_write($key, (string)json_encode($info + ['expires' => time() + $ttl], JSON_UNESCAPED_UNICODE), 'application/json');
    return $info;
}

/** @param array{status: string, code?: string} $info */
function link_code(array $info): string
{
    return $info['status'] === 'ok' ? 'RQ00000' : (string)($info['code'] ?? $info['status']);
}

/**
 * Какой экран показать вместо оплаты. null — ссылка в порядке либо НСПК не ответила, а строгого режима нет.
 * QR чужого мерчанта выглядит как недействительная ссылка.
 *
 * @param array{status: string, code?: string, message?: string, expired?: bool} $info
 * @param list<string> $allowed
 * @return array{kind: string, code?: string, message?: string}|null
 */
function link_state(array $info, array $allowed, bool $strict): ?array
{
    if ($info['status'] === 'ok') {
        return merchant_allowed($info, $allowed) ? null : ['kind' => 'invalid'];
    }
    if ($info['status'] === 'rejected') {
        $code = (string)($info['code'] ?? '');
        $kind = !empty($info['expired']) ? 'expired' : (in_array($code, STARTED_CODES, true) ? 'started' : 'error');
        return ['kind' => $kind, 'code' => $code, 'message' => (string)($info['message'] ?? '')];
    }
    // НСПК ответила кодом, которого нет в её справочнике: страница НСПК в этом случае тоже показывает ошибку.
    if (preg_match('/^RQ\d{5}$/', (string)($info['code'] ?? ''))) {
        return ['kind' => 'error', 'code' => $info['code'], 'message' => ''];
    }
    return $strict && $allowed !== [] ? ['kind' => 'unavailable'] : null;
}

/**
 * Мерчанты, которым зеркало показывает оплату: merchantId или legalId из ответа НСПК через запятую.
 * Пустой список — проверки нет.
 *
 * @return list<string>
 */
function allowed_merchants(): array
{
    return array_values(array_filter(array_map('trim', explode(',', strtoupper((string)getenv('ALLOWED_MERCHANTS'))))));
}

/**
 * @param array{merchantId?: string, legalId?: string} $info
 * @param list<string> $allowed
 */
function merchant_allowed(array $info, array $allowed): bool
{
    return $allowed === []
        || in_array($info['merchantId'] ?? '', $allowed, true)
        || in_array($info['legalId'] ?? '', $allowed, true);
}

function strict_mode(): bool
{
    return getenv('MERCHANT_STRICT') === '1';
}

/** Подпись QRC_ID для /api/link: эндпоинт отвечает только страницам, которые отдало само зеркало. */
function link_sig(string $qrcId): string
{
    return substr(hash_hmac('sha256', $qrcId, mirror_secret()), 0, 24);
}

/** Секрет из MIRROR_SECRET, иначе один раз создаётся в томе кэша и переживает пересборку. */
function mirror_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $env = (string)getenv('MIRROR_SECRET');
    if (strlen($env) >= 16) {
        return $secret = $env;
    }
    $file = CACHE_DIR . '/secret';
    $fh = @fopen($file, 'x');
    if ($fh !== false) {
        fwrite($fh, bin2hex(random_bytes(32)));
        fclose($fh);
    }
    $stored = (string)@file_get_contents($file);
    return $secret = strlen($stored) >= 32 ? $stored : hash('sha256', __FILE__ . php_uname());
}

/**
 * Ответ /api/link для страницы: без merchantId и legalId, с готовым экраном для отказа.
 *
 * @param array{status: string} $info
 * @return array<string, mixed>
 */
function public_link_info(array $info, ?array $state): array
{
    if ($state !== null) {
        return ['status' => 'rejected', 'state' => $state['kind']]
            + array_filter(['code' => $state['code'] ?? null, 'message' => $state['message'] ?? null]);
    }
    unset($info['merchantId'], $info['legalId']);
    return $info;
}

/**
 * Адрес payment-link, параметры PoW и тексты ошибок из apiConfig.json.
 * Апстрим без проверки сертификата: адрес пускаем только на *.cbrpay.ru, параметры PoW
 * держим в рамках, иначе подменённый конфиг мог бы увести запрос или повесить PHP перебором.
 *
 * @return array{baseURL: string, params: array{key: string, paramsVersion: string, difficulty: int|float, maxTimeThresholdMs: int, maxPowCount: int}, errors: array<string, string>}
 */
function parse_api_config(string $json): array
{
    $c = json_decode($json, true);
    $c = is_array($c) ? $c : [];

    $base = (string)($c['baseURL'] ?? '');
    $p = is_array($c['params'] ?? null) ? $c['params'] : [];
    $difficulty = $p['difficulty'] ?? null;
    $valid = preg_match('/^[0-9A-Fa-f]{32}$/', (string)($p['key'] ?? '')) === 1
        && preg_match('/^[\w.-]{1,64}$/', (string)($p['paramsVersion'] ?? '')) === 1
        && (is_int($difficulty) || is_float($difficulty)) && $difficulty >= 2 ** 50 && $difficulty <= 2 ** 63
        && is_int($p['maxPowCount'] ?? null) && $p['maxPowCount'] >= 1 && $p['maxPowCount'] <= 50
        && is_int($p['maxTimeThresholdMs'] ?? null) && $p['maxTimeThresholdMs'] >= 50 && $p['maxTimeThresholdMs'] <= 3000;

    $errors = LINK_ERRORS;
    foreach (is_array($c['errorMessages'] ?? null) ? $c['errorMessages'] : [] as $e) {
        $message = is_array($e) ? trim((string)($e['message'] ?? '')) : '';
        foreach (is_array($e['listOfCodes'] ?? null) ? $e['listOfCodes'] : [] as $code) {
            if ($message !== '' && preg_match('/^RQ\d{5}$/', (string)$code)) {
                $errors[(string)$code] = mb_substr($message, 0, 300);
            }
        }
    }

    return [
        'baseURL' => preg_match('#^https://[a-z0-9.-]+\.cbrpay\.ru/[A-Za-z0-9/_-]+$#', $base) ? rtrim($base, '/') : PAYMENT_LINK_URL,
        'params' => $valid ? [
            'key' => (string)$p['key'],
            'paramsVersion' => (string)$p['paramsVersion'],
            'difficulty' => $difficulty,
            'maxTimeThresholdMs' => $p['maxTimeThresholdMs'],
            'maxPowCount' => $p['maxPowCount'],
        ] : POW_DEFAULTS,
        'errors' => $errors,
    ];
}

/**
 * @param array<string, string> $errors код НСПК → текст для покупателя
 * @return array{status: string, amount?: string, amountLabel?: string, merchant?: string, code?: string, message?: string, expired?: bool}
 */
function parse_link_response(int $status, string $body, array $errors): array
{
    $data = json_decode($body, true);
    $code = is_array($data) ? (string)($data['code'] ?? '') : '';

    if ($status === 200 && $code === 'RQ00000' && is_array($data['data'] ?? null)) {
        $info = ['status' => 'ok'];
        $amount = (string)($data['data']['amount'] ?? '');
        if (preg_match('/^\d{1,12}$/', $amount) && (int)$amount > 0) {
            $info['amount'] = $amount;
            $info['amountLabel'] = format_amount((int)$amount);
        }
        $merchant = trim((string)($data['data']['brandName'] ?? ''));
        if ($merchant !== '') {
            $info['merchant'] = mb_substr($merchant, 0, 80);
        }
        foreach (['merchantId', 'legalId'] as $field) {
            $id = strtoupper(trim((string)($data['data'][$field] ?? '')));
            if (preg_match('/^[A-Z]{2}\d{6,16}$/', $id)) {
                $info[$field] = $id;
            }
        }
        return $info;
    }
    if (isset($errors[$code])) {
        return ['status' => 'rejected', 'code' => $code, 'message' => $errors[$code], 'expired' => in_array($code, EXPIRED_CODES, true)];
    }
    return preg_match('/^RQ\d{5}$/', $code) ? ['status' => 'error', 'code' => $code] : ['status' => 'error'];
}
