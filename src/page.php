<?php
declare(strict_types=1);

namespace NspkMirror;

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
}

/** Адрес файла из assets с версией по времени изменения, чтобы браузер не держал старые CSS и JS. */
function asset(string $file): string
{
    $mtime = @filemtime(rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/assets/' . $file);
    return '/assets/' . $file . ($mtime ? '?v=' . base_convert((string)$mtime, 10, 36) : '');
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

function icon(string $name): string
{
    $paths = [
        'search' => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
        'qr' => '<rect width="5" height="5" x="3" y="3" rx="1"/><rect width="5" height="5" x="16" y="3" rx="1"/><rect width="5" height="5" x="3" y="16" rx="1"/><path d="M21 16h-3a2 2 0 0 0-2 2v3"/><path d="M21 21v.01"/><path d="M12 7v3a2 2 0 0 1-2 2H7"/><path d="M3 12h.01"/><path d="M12 3h.01"/><path d="M12 16v.01"/><path d="M16 12h1"/><path d="M21 12v.01"/><path d="M12 21v-1"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/>',
        'download' => '<path d="M12 15V3"/><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'arrow' => '<path d="M7 7h10v10"/><path d="M7 17 17 7"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
    ];
    return '<svg class="i" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2"'
        . ' stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths[$name] . '</svg>';
}

/**
 * @param array{
 *     banks: list<array{name: string, schema: string, logo: string, link: string}>,
 *     amount: array{0: string, 1: string}|null,
 *     nspkUrl: string, pageUrl: string, fallbackUrl: string, mobile: bool
 * } $v
 */
function render_page(array $v): string
{
    $title = $v['amount'] !== null ? $v['amount'][0] . "\u{00A0}" . $v['amount'][1] . ' — оплата через СБП' : 'Оплата через СБП';
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
<body class="<?= $v['mobile'] ? 'is-mobile' : 'is-desktop' ?>">
<header class="head">
  <span class="chip"><img src="/assets/sbp-mark.svg" width="14" height="17" alt="">Система быстрых платежей</span>
<?php if ($v['amount'] !== null): ?>
  <h1 class="amount"><?= h($v['amount'][0]) ?><span class="cur"><?= h($v['amount'][1]) ?></span></h1>
<?php else: ?>
  <h1 class="title">Оплата через СБП</h1>
<?php endif ?>
  <p class="lead only-mobile">Выберите банк, и его приложение откроется с уже заполненным платежом</p>
  <p class="lead only-desktop">Отсканируйте QR-код в приложении банка на телефоне</p>
</header>

<main class="shell">
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
</main>

<footer class="foot">
  <img src="/assets/sbp-wordmark.svg" width="62" height="24" alt="СБП, Система быстрых платежей">
  <p>Деньги списываются в приложении вашего банка</p>
</footer>

<dialog class="sheet" id="web-banks" aria-labelledby="sheet-title">
  <div class="sheet-head">
    <div>
      <h2 class="sheet-title" id="sheet-title">Интернет-банк в браузере</h2>
      <p class="sheet-sub">Откроется сайт банка с этим платежом, останется войти и подтвердить</p>
    </div>
    <button class="sheet-close" type="button" data-close aria-label="Закрыть"><?= icon('x') ?></button>
  </div>
</dialog>
<script src="<?= h(asset('qrcode.js')) ?>" defer></script>
<script src="<?= h(asset('app.js')) ?>" defer></script>
</body>
</html>
<?php
    return (string)ob_get_clean();
}
