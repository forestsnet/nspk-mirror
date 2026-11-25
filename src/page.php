<?php
declare(strict_types=1);

namespace NspkMirror;

/** Экраны вместо оплаты. */
const STATES = [
    'invalid' => [
        'http' => 404, 'tone' => 'danger', 'icon' => 'unlink',
        'title' => 'Ссылка недействительна',
        'text' => 'Такой ссылки на оплату нет. Проверьте её или вернитесь в магазин и создайте платёж заново.',
    ],
    'expired' => [
        'http' => 410, 'tone' => 'danger', 'icon' => 'hourglass',
        'title' => 'Время на оплату вышло',
        'text' => 'Ссылка на оплату больше не действует. Если вы уже оплатили, проверьте статус заказа в магазине. Если нет, создайте платёж заново.',
    ],
    'started' => [
        'http' => 200, 'tone' => 'accent', 'icon' => 'clock',
        'title' => 'Оплата уже идёт',
        'text' => 'По этому QR-коду уже началась оплата. Дождитесь её завершения в приложении банка.',
    ],
    'error' => [
        'http' => 200, 'tone' => 'danger', 'icon' => 'alert',
        'title' => 'Оплатить не получится',
        'text' => 'Банк отклонил операцию. Выберите другой способ оплаты или создайте платёж заново.',
    ],
    'unavailable' => [
        'http' => 503, 'tone' => 'muted', 'icon' => 'cloud-off',
        'title' => 'Не удалось проверить ссылку',
        'text' => 'Сервис оплаты СБП сейчас не отвечает. Попробуйте ещё раз через минуту.',
    ],
    'paid' => [
        'http' => 200, 'tone' => 'ok', 'icon' => 'check',
        'title' => 'Оплата завершена',
        'text' => 'Банк закончил платёж по этому QR-коду. Статус заказа будет в магазине.',
    ],
];

/**
 * Атрибуты body для скрипта страницы. Адреса возврата в HTML не попадают, только признаки, что они есть.
 *
 * @param array{qrcId?: string, sig?: string, verified?: bool, started?: bool, expiresIn?: int|null, ret?: array<string, string>|null} $ctx
 */
function body_attrs(bool $mobile, array $ctx, string $extraClass = ''): string
{
    $attrs = ['class' => trim(($mobile ? 'is-mobile' : 'is-desktop') . ' ' . $extraClass)];
    if (isset($ctx['qrcId'])) {
        $attrs['data-qrc'] = $ctx['qrcId'];
    }
    if (isset($ctx['sig'])) {
        $attrs['data-sig'] = $ctx['sig'];
    }
    if (!empty($ctx['verified'])) {
        $attrs['data-status'] = 'ok';
    }
    if (!empty($ctx['started'])) {
        $attrs['data-started'] = '1';
    }
    if (isset($ctx['expiresIn'])) {
        $attrs['data-exp-in'] = (string)(int)$ctx['expiresIn'];
    }
    $ret = $ctx['ret'] ?? null;
    if (is_array($ret)) {
        $attrs['data-ret'] = $ret['token'];
        foreach (RETURN_FIELDS as $field) {
            if (($ret[$field] ?? '') !== '') {
                $attrs['data-ret-' . $field] = '1';
            }
        }
    }
    return implode(' ', array_map(static fn(string $k, string $v): string => $k . '="' . h($v) . '"', array_keys($attrs), $attrs));
}

/** Аватарка магазина через /a/: зеркало само скачивает и ужимает её под ×1, ×2, ×3. */
function avatar_img(string $qrcId, array $ret): string
{
    $src = static fn(int $s): string => '/a/' . $qrcId . '?t=' . $ret['token'] . '&s=' . $s;
    return '<img class="avatar" src="' . h($src(24)) . '" srcset="' . h($src(48)) . ' 2x, ' . h($src(72)) . ' 3x" width="24" height="24" alt="">';
}

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/**
 * Собранные JS и CSS лежат под именами-хешами, карту пишет сборка образа.
 *
 * @return array<string, string>
 */
function asset_manifest(): array
{
    static $manifest = null;
    $manifest ??= json_decode((string)@file_get_contents(__DIR__ . '/../data/assets.json'), true) ?: [];
    return $manifest;
}

/** Адрес файла из assets: собранный, если есть сборка, иначе исходник с версией по времени изменения. */
function asset(string $file): string
{
    $built = asset_manifest()[$file] ?? null;
    if (is_string($built)) {
        return '/assets/b/' . $built;
    }
    $mtime = @filemtime(rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/assets/' . $file);
    return '/assets/' . $file . ($mtime ? '?v=' . base_convert((string)$mtime, 10, 36) : '');
}

function script_tags(): string
{
    $files = isset(asset_manifest()['app.js']) ? ['app.js'] : ['qrcode.js', 'app.js'];
    return implode("\n", array_map(static fn(string $f): string => '<script src="' . h(asset($f)) . '" defer></script>', $files));
}

function is_mobile_ua(string $ua): bool
{
    return (bool)preg_match('/iPhone|iPad|iPod|Android|Mobile|HarmonyOS/i', $ua);
}

/** Буква на случай, если логотип не загрузился: «Банк ВТБ» → «В». */
function initial(string $name): string
{
    $name = (string)preg_replace('/^(?:банк|кб|акб|пао|ао)\s+/iu', '', trim($name));
    return mb_strtoupper(mb_substr($name, 0, 1));
}

function icon(string $name, string $class = 'i', float $stroke = 2): string
{
    $paths = [
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'qr' => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'arrow' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'alert' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'unlink' => '<path d="M9 17H7A5 5 0 0 1 7 7"/><path d="M15 7h2a5 5 0 0 1 4 8"/><path d="M8 12h4"/><path d="m2 2 20 20"/>',
        'hourglass' => '<path d="M5 22h14"/><path d="M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'cloud-off' => '<path d="m2 2 20 20"/><path d="M5.782 5.782A7 7 0 0 0 9 19h8.5a4.5 4.5 0 0 0 1.307-.193"/><path d="M21.532 16.5A4.5 4.5 0 0 0 17.5 10h-1.79A7.008 7.008 0 0 0 10 5.07"/>',
        'check' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
    ];
    return '<svg class="' . h($class) . '" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="' . $stroke . '"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

/** Общий каркас: плашка СБП сверху, содержимое по центру, подвал снизу. */
function layout(string $title, string $bodyAttrs, string $main, string $after = ''): string
{
    ob_start();
    ?>
<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#0c0e12">
<meta name="format-detection" content="telephone=no">
<title><?= h($title) ?></title>
<link rel="preload" href="/assets/fonts/unbounded-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/onest-cyrillic.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?= h(asset('app.css')) ?>">
</head>
<body <?= $bodyAttrs ?>>
<header class="head">
  <span class="chip"><img src="/assets/sbp-mark.svg" width="14" height="17" alt="">Система быстрых платежей</span>
</header>

<main class="shell">
<?= $main ?>
</main>

<footer class="foot">
  <img src="/assets/sbp-wordmark.svg" width="62" height="24" alt="СБП, Система быстрых платежей">
  <p>Деньги списываются в приложении вашего банка</p>
</footer>
<?= $after ?>
<?= script_tags() ?>

</body>
</html>
<?php
    return (string)ob_get_clean();
}

/**
 * Экран вместо оплаты.
 *
 * @param array{kind: string, code?: string, message?: string} $state
 */
function state_section(array $state): string
{
    $kind = $state['kind'];
    $s = STATES[$kind];
    $message = trim($state['message'] ?? '');
    $text = $kind === 'error' && $message !== '' ? $message : $s['text'];
    $code = (string)($state['code'] ?? '');
    $reload = ['started' => 'Проверить статус', 'unavailable' => 'Попробовать снова'][$kind] ?? null;
    ob_start();
    ?>
<section class="state" data-kind="<?= h($kind) ?>" data-tone="<?= h($s['tone']) ?>">
  <div class="state-art" aria-hidden="true">
    <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
    <?= icon($s['icon'], 'state-icon', 1.6) ?>
  </div>
  <h1 class="state-title"><?= h($s['title']) ?></h1>
  <p class="state-text"><?= h($text) ?></p>
  <p class="state-meta" hidden></p>
  <div class="state-actions">
<?php if ($reload !== null): ?>
    <button class="btn btn-primary" type="button" data-action="reload"><?= h($reload) ?></button>
<?php endif ?>
<?php if ($kind !== 'unavailable'): ?>
    <button class="btn <?= $reload !== null ? 'btn-ghost' : 'btn-primary' ?>" type="button" data-action="back" hidden>Вернуться назад</button>
<?php endif ?>
  </div>
  <p class="state-countdown" hidden></p>
<?php if (preg_match('/^RQ\d{5}$/', $code)): ?>
  <p class="state-code">Код НСПК <?= h($code) ?></p>
<?php endif ?>
</section>
<?php
    return (string)ob_get_clean();
}

/**
 * @param array{kind: string, code?: string, message?: string} $state
 * @param array<string, mixed> $ctx то же, что для body_attrs: возврат в магазин, а для «Оплата уже идёт» ещё и слежение
 */
function render_state(array $state, bool $mobile, array $ctx = []): string
{
    return layout(STATES[$state['kind']]['title'], body_attrs($mobile, $ctx, 'is-state'), state_section($state));
}

/**
 * @param array{
 *     qrcId: string,
 *     banks: list<array{name: string, schema: string, logo: string, link: string}>,
 *     amount: array{0: string, 1: string}|null,
 *     nspkUrl: string, pageUrl: string, fallbackUrl: string, mobile: bool,
 *     merchant?: string|null, verified?: bool, sig?: string, expiresIn?: int|null, ret?: array<string, string>|null
 * } $v
 */
function render_page(array $v): string
{
    $title = $v['amount'] !== null ? $v['amount'][0] . "\u{00A0}" . $v['amount'][1] . ' — оплата через СБП' : 'Оплата через СБП';
    $ret = $v['ret'] ?? null;
    $shop = is_array($ret) ? $ret['shop'] : '';
    $merchant = (string)($v['merchant'] ?? '');
    if ($merchant === '' && $shop !== '') {
        $merchant = (string)parse_url($shop, PHP_URL_HOST);
    }
    $attrs = body_attrs($v['mobile'], $v);
    $name = $shop !== '' ? '<a href="' . h($shop) . '" target="_blank" rel="noopener">' . h($merchant) . '</a>' : h($merchant);
    $avatar = is_array($ret) && $ret['avatar'] !== '' ? avatar_img($v['qrcId'], $ret) : '';

    ob_start();
    ?>
  <div class="summary">
<?php if ($v['amount'] !== null): ?>
    <h1 class="amount"><?= h($v['amount'][0]) ?><span class="cur"><?= h($v['amount'][1]) ?></span></h1>
<?php else: ?>
    <h1 class="title">Оплата через СБП</h1>
<?php endif ?>
    <p class="merchant"<?= $merchant === '' ? ' hidden' : '' ?>><?= $avatar ?><span>Получатель</span> <b><?= $name ?></b></p>
    <div class="timer" hidden>
      <p><span>Оплатите в течение</span> <b class="timer-left"></b></p>
      <span class="timer-bar"><i></i></span>
    </div>
    <p class="lead only-mobile">Выберите банк, и его приложение откроется с уже заполненным платежом</p>
    <p class="lead only-desktop">Отсканируйте QR-код в приложении банка на телефоне</p>
  </div>

  <div class="alert" role="alert" hidden>
    <?= icon('alert') ?>
    <p class="alert-text"></p>
  </div>

  <section class="panel banks" id="banks" aria-labelledby="banks-title">
    <h2 class="panel-title" id="banks-title">Выберите банк</h2>
    <label class="search">
      <?= icon('search') ?>
      <input type="search" placeholder="Поиск банка" autocomplete="off" autocapitalize="off" spellcheck="false" enterkeyhint="search" aria-label="Поиск банка">
    </label>
    <ul class="grid" role="list">
<?php foreach ($v['banks'] as $i => $b): ?>
      <li class="bank" data-name="<?= h($b['name']) ?>" data-schema="<?= h($b['schema']) ?>" data-web="<?= str_starts_with($b['link'], 'https://') ? '1' : '0' ?>">
        <a class="tile" href="<?= h($b['link']) ?>">
          <span class="logo<?= $b['logo'] === '' ? ' is-broken' : '' ?>" data-initial="<?= h(initial($b['name'])) ?>"><?php if ($b['logo'] !== ''): ?><img src="/logo/40/<?= h($b['logo']) ?>" srcset="/logo/80/<?= h($b['logo']) ?> 2x, /logo/120/<?= h($b['logo']) ?> 3x" width="40" height="40" alt=""<?= $i >= 12 ? ' loading="lazy"' : '' ?> decoding="async"><?php endif ?></span>
          <span class="name"><?= h($b['name']) ?></span>
        </a>
      </li>
<?php endforeach ?>
    </ul>
    <p class="empty" hidden>Банк не нашёлся. Проверьте название или оплатите по QR-коду в приложении своего банка.</p>
    <p class="note only-mobile">Если приложение не открылось, обновите его или выберите другой банк</p>
  </section>

  <div class="actions">
    <button class="btn btn-ghost only-mobile" type="button" data-toggle="show-qr" aria-controls="qr" aria-expanded="false" data-on="Скрыть QR-код" data-off="Показать QR-код"><?= icon('qr') ?><span>Показать QR-код</span></button>
    <button class="btn btn-ghost only-desktop" type="button" data-toggle="show-banks" aria-controls="banks" aria-expanded="false" data-on="Скрыть интернет-банки" data-off="Оплатить в интернет-банке"><?= icon('globe') ?><span>Оплатить в интернет-банке</span></button>
  </div>

  <section class="panel qr-panel" id="qr" aria-label="QR-код для оплаты" data-app="<?= h($v['nspkUrl']) ?>" data-camera="<?= h($v['pageUrl']) ?>">
    <div class="seg" role="tablist" aria-label="Чем сканировать">
      <button type="button" role="tab" aria-selected="true" data-mode="app">Приложением банка</button>
      <button type="button" role="tab" aria-selected="false" data-mode="camera">Камерой телефона</button>
    </div>
    <figure class="qr-frame">
      <img class="qr-img" alt="QR-код для оплаты через СБП" width="232" height="232">
      <span class="scan" aria-hidden="true"></span>
      <i class="corner tl"></i><i class="corner tr"></i><i class="corner bl"></i><i class="corner br"></i>
    </figure>
    <noscript><p class="note">Чтобы показать QR-код, включите JavaScript</p></noscript>
    <ol class="steps" data-mode="app">
      <li>Откройте приложение банка</li>
      <li>Выберите оплату по&nbsp;QR-коду</li>
      <li>Отсканируйте и&nbsp;подтвердите платёж</li>
    </ol>
    <ol class="steps" data-mode="camera" hidden>
      <li>Откройте камеру телефона</li>
      <li>Наведите на&nbsp;код и&nbsp;откройте ссылку</li>
      <li>Выберите банк на&nbsp;открывшейся странице</li>
    </ol>
    <a class="btn btn-ghost btn-save" data-action="save-qr" download="sbp-qr.png" href="#"><?= icon('download') ?><span>Сохранить QR-код</span></a>
    <p class="note only-ios">Или нажмите на QR-код и удерживайте, чтобы сохранить его в Фото</p>
  </section>

  <details class="panel help">
    <summary><span>Не работает оплата?</span><?= icon('chevron') ?></summary>
    <ul class="tips">
      <li><b>Приложение не открылось.</b> Обновите или установите приложение банка и снова нажмите на банк в списке.</li>
      <li><b>Вашего банка нет в списке.</b> Откройте приложение банка, найдите оплату по QR-коду и отсканируйте код. На телефоне QR-код можно сохранить картинкой и загрузить в приложение.</li>
      <li><b>Банк пишет, что код недействителен.</b> Ссылка на оплату живёт ограниченное время. Вернитесь в магазин и создайте платёж заново.</li>
    </ul>
    <a class="more" href="<?= h($v['fallbackUrl']) ?>">Открыть страницу оплаты НСПК<?= icon('arrow') ?></a>
  </details>
<?php
    $main = (string)ob_get_clean();

    ob_start();
    ?>
<dialog class="sheet" id="web-banks" aria-labelledby="sheet-title">
  <div class="sheet-top">
    <div class="sheet-head">
      <div>
        <h2 class="sheet-title" id="sheet-title">Интернет-банк в браузере</h2>
        <p class="sheet-sub">Откроется сайт банка с этим платежом, останется войти и подтвердить</p>
      </div>
      <button class="sheet-close" type="button" data-close aria-label="Закрыть"><?= icon('x') ?></button>
    </div>
  </div>
</dialog>
<?php foreach (['expired', 'started', 'invalid', 'paid'] as $kind): ?>
<template id="state-<?= $kind ?>"><?= state_section(['kind' => $kind]) ?></template>
<?php endforeach ?>
<?php
    return layout($title, $attrs, $main, (string)ob_get_clean());
}
