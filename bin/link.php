<?php
// Запуск: docker compose exec -u www-data mirror php /var/www/bin/link.php <QRC_ID>
// Показывает ответ НСПК по ссылке вместе с merchantId и legalId — их вписывают в ALLOWED_MERCHANTS.
declare(strict_types=1);

namespace NspkMirror;

require __DIR__ . '/../src/rewrite.php';
require __DIR__ . '/../src/upstream.php';
require __DIR__ . '/../src/banks.php';
require __DIR__ . '/../src/pow.php';
require __DIR__ . '/../src/link.php';

$id = $argv[1] ?? '';
if (!preg_match('/^[A-Za-z0-9]{32}$/', $id)) {
    fwrite(STDERR, "usage: php link.php <QRC_ID>\n");
    exit(2);
}
$info = link_info($id, 'Mozilla/5.0');
$info['allowed'] = merchant_allowed($info, allowed_merchants());
echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
