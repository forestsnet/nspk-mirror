<?php
declare(strict_types=1);

namespace NspkMirror;

// Потолок перебора на всю цепочку, около 3 секунд PHP.
const POW_MAX_TRIES = 200000;

/**
 * SipHash-2-4 с 16-байтным ключом, как в коде страницы НСПК. PHP int знаковый и при переполнении
 * уходит во float, поэтому 64-битные слова считаются половинами по 32 бита.
 *
 * @return array{0: int, 1: int} [старшие 32 бита, младшие 32 бита]
 */
function siphash24(string $msg, string $key): array
{
    $k = unpack('V4', $key);
    $v0h = $k[2] ^ 0x736f6d65;
    $v0l = $k[1] ^ 0x70736575;
    $v1h = $k[4] ^ 0x646f7261;
    $v1l = $k[3] ^ 0x6e646f6d;
    $v2h = $k[2] ^ 0x6c796765;
    $v2l = $k[1] ^ 0x6e657261;
    $v3h = $k[4] ^ 0x74656462;
    $v3l = $k[3] ^ 0x79746573;

    $len = strlen($msg);
    $tail = $len & 7;
    $words = unpack('V*', substr($msg, 0, $len - $tail) . substr($msg, $len - $tail) . str_repeat("\0", 7 - $tail) . chr($len & 0xff));

    $count = count($words);
    for ($i = 1; $i <= $count; $i += 2) {
        $ml = $words[$i];
        $mh = $words[$i + 1];
        $v3h ^= $mh;
        $v3l ^= $ml;
        sipround($v0h, $v0l, $v1h, $v1l, $v2h, $v2l, $v3h, $v3l);
        sipround($v0h, $v0l, $v1h, $v1l, $v2h, $v2l, $v3h, $v3l);
        $v0h ^= $mh;
        $v0l ^= $ml;
    }
    $v2l ^= 0xff;
    for ($r = 0; $r < 4; $r++) {
        sipround($v0h, $v0l, $v1h, $v1l, $v2h, $v2l, $v3h, $v3l);
    }
    return [$v0h ^ $v1h ^ $v2h ^ $v3h, $v0l ^ $v1l ^ $v2l ^ $v3l];
}

function sipround(int &$v0h, int &$v0l, int &$v1h, int &$v1l, int &$v2h, int &$v2l, int &$v3h, int &$v3l): void
{
    // v0 += v1, v1 <<<= 13, v1 ^= v0, v0 <<<= 32
    $l = $v0l + $v1l;
    $v0h = ($v0h + $v1h + ($l >> 32)) & 0xffffffff;
    $v0l = $l & 0xffffffff;
    [$v1h, $v1l] = [(($v1h << 13) | ($v1l >> 19)) & 0xffffffff, (($v1l << 13) | ($v1h >> 19)) & 0xffffffff];
    $v1h ^= $v0h;
    $v1l ^= $v0l;
    [$v0h, $v0l] = [$v0l, $v0h];

    // v2 += v3, v3 <<<= 16, v3 ^= v2
    $l = $v2l + $v3l;
    $v2h = ($v2h + $v3h + ($l >> 32)) & 0xffffffff;
    $v2l = $l & 0xffffffff;
    [$v3h, $v3l] = [(($v3h << 16) | ($v3l >> 16)) & 0xffffffff, (($v3l << 16) | ($v3h >> 16)) & 0xffffffff];
    $v3h ^= $v2h;
    $v3l ^= $v2l;

    // v0 += v3, v3 <<<= 21, v3 ^= v0
    $l = $v0l + $v3l;
    $v0h = ($v0h + $v3h + ($l >> 32)) & 0xffffffff;
    $v0l = $l & 0xffffffff;
    [$v3h, $v3l] = [(($v3h << 21) | ($v3l >> 11)) & 0xffffffff, (($v3l << 21) | ($v3h >> 11)) & 0xffffffff];
    $v3h ^= $v0h;
    $v3l ^= $v0l;

    // v2 += v1, v1 <<<= 17, v1 ^= v2, v2 <<<= 32
    $l = $v2l + $v1l;
    $v2h = ($v2h + $v1h + ($l >> 32)) & 0xffffffff;
    $v2l = $l & 0xffffffff;
    [$v1h, $v1l] = [(($v1h << 17) | ($v1l >> 15)) & 0xffffffff, (($v1l << 17) | ($v1h >> 15)) & 0xffffffff];
    $v1h ^= $v2h;
    $v1l ^= $v2l;
    [$v2h, $v2l] = [$v2l, $v2h];
}

/** Хеш, который страница НСПК сравнивает со сложностью: SipHash как знаковое 64-битное число, по модулю. */
function pow_hash(string $msg, string $key): int|float
{
    [$hi, $lo] = siphash24($msg, $key);
    $signed = ($hi << 32) | $lo;
    return $signed === PHP_INT_MIN ? 9.2233720368547758E+18 : abs($signed);
}

/**
 * Заголовки X-Token-* для payment-link API: цепочка хешей «nonce;guid;время;прошлый хеш» меньше сложности,
 * как её строит страница НСПК. После первого хеша цепочка обрывается по maxTimeThresholdMs.
 *
 * @param array{key: string, paramsVersion: string, difficulty: int|float, maxTimeThresholdMs: int, maxPowCount: int} $params
 * @return list<string>
 */
function pow_headers(array $params, ?string $guid = null): array
{
    $key = (string)hex2bin($params['key']);
    // В JS сложность проходит через Number: 72057594037927940 превращается в 2^56.
    $difficulty = (int)(float)$params['difficulty'];
    $guid ??= uuid4();
    $start = now_ms();
    $hash = '0';
    $nonce = 0;
    $stamps = [];
    $hashes = [];
    $nonces = [];

    for ($d = 0; $d < $params['maxPowCount']; $d++) {
        $t = now_ms();
        do {
            $nonce++;
            $h = pow_hash("$nonce;$guid;$t;$hash", $key);
            if ($nonce > POW_MAX_TRIES || ($d > 0 && now_ms() - $start > $params['maxTimeThresholdMs'])) {
                break 2;
            }
        } while ($h >= $difficulty);
        $hash = (string)$h;
        $stamps[] = $t;
        $hashes[] = $hash;
        $nonces[] = $nonce;
    }
    $stamps[] = now_ms();

    return [
        'X-Token-Version: ' . $params['paramsVersion'],
        'X-Token-Timestamp: ' . implode(';', $stamps),
        'X-Token-Pow: ' . implode(';', $hashes),
        'X-Token-Nonce: ' . implode(';', $nonces),
        'X-Token-Guid: ' . $guid,
    ];
}

function now_ms(): int
{
    return (int)floor(microtime(true) * 1000);
}

function uuid4(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}
