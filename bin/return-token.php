<?php
// Запуск: docker compose exec mirror php /var/www/bin/return-token.php <QRC_ID> ok=<url> fail=<url> shop=<url> avatar=<url>
// Собирает mx_r с ключом RETURN_KEY контейнера, любое поле можно опустить.
declare(strict_types=1);

namespace NspkMirror;

require __DIR__ . '/../src/ret.php';

$id = $argv[1] ?? '';
$data = [];
foreach (array_slice($argv, 2) as $arg) {
    [$name, $value] = array_pad(explode('=', $arg, 2), 2, '');
    if (in_array($name, RETURN_FIELDS, true)) {
        $data[$name] = $value;
    }
}
$key = return_key();
if (!preg_match('/^[A-Za-z0-9]{32}$/', $id) || $data === [] || $key === null) {
    fwrite(STDERR, "usage: php return-token.php <QRC_ID> ok=<url> fail=<url> shop=<url> avatar=<url>, нужен RETURN_KEY\n");
    exit(2);
}
echo seal_return_token($data, $id, $key), "\n";
