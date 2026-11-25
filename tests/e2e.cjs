'use strict';
// Запуск: node tests/e2e.cjs [url ...], нужен playwright-core (см. docs/development.md).
// Для сравнения с оригиналом можно передать https://qr.nspk.ru/<QRC_ID>, сертификат НУЦ игнорируется.
const { chromium } = require('playwright-core');

const urls = process.argv.slice(2);
if (urls.length === 0) urls.push('https://127.0.0.1:8443/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH');

const PROFILES = {
  iphone: {
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
    viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true,
  },
  android: {
    userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Mobile Safari/537.36',
    viewport: { width: 412, height: 915 }, isMobile: true, hasTouch: true,
  },
  desktop: {
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36',
    viewport: { width: 1280, height: 800 },
  },
};
// Хосты с сертификатом Минцифры: браузер без корня НУЦ до них не достучится.
const RU_CA_HOSTS = /(^|\.)(nspk\.ru|cbrpay\.ru)$/;
// Sentry НСПК: если не дойдёт, оплате это не мешает.
const IGNORED = /^mon\.nspk\.ru$/;

// Ловим, что страница кладёт в QR-код (библиотека qr-code-styling).
function captureQrData() {
  let real;
  const seen = (window.__qrData = []);
  Object.defineProperty(window, 'QRCodeStyling', {
    configurable: true,
    get: () => real,
    set: (cls) => {
      const update = cls.prototype.update;
      cls.prototype.update = function (opts) {
        if (opts && opts.data) seen.push(opts.data);
        return update.apply(this, arguments);
      };
      real = new Proxy(cls, {
        construct(target, args) {
          if (args[0] && args[0].data) seen.push(args[0].data);
          return Reflect.construct(target, args);
        },
      });
    },
  });
}

async function run(browser, url, name, profile) {
  const pageOrigin = new URL(url).origin;
  const isMirror = !RU_CA_HOSTS.test(new URL(url).hostname);
  const context = await browser.newContext({ ...profile, ignoreHTTPSErrors: true, locale: 'ru-RU' });
  await context.addInitScript(captureQrData);
  const page = await context.newPage();
  const direct = new Set();
  const bad = [];
  const api = [];
  const errors = [];
  const qrData = [];
  page.on('request', (req) => {
    const u = new URL(req.url());
    if (u.origin !== pageOrigin && RU_CA_HOSTS.test(u.hostname) && !IGNORED.test(u.hostname)) {
      direct.add(`${req.method()} ${u.origin}${u.pathname}`);
    }
  });
  page.on('requestfailed', (req) => {
    if (!IGNORED.test(new URL(req.url()).hostname)) bad.push(`${req.failure()?.errorText} ${req.url()}`);
  });
  page.on('response', async (res) => {
    const u = new URL(res.url());
    if (res.status() >= 400) bad.push(`${res.status()} ${res.url()}`);
    if (/payment-link|\/members\//.test(u.pathname)) {
      let body = '';
      try { body = (await res.text()).replace(/\s+/g, ' ').slice(0, 400); } catch { /* тело недоступно */ }
      api.push(`${res.request().method()} ${u.pathname} → ${res.status()} ${body}`);
    }
  });
  page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
  page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));

  const logos = { mirror: 0, direct: 0 };
  page.on('requestfinished', (req) => {
    const u = new URL(req.url());
    if (/\/(proxyapp\/)?logo\//.test(u.pathname)) logos[u.origin === pageOrigin ? 'mirror' : 'direct']++;
  });

  // networkidle не наступает: страница НСПК держит фоновые запросы. Ждём банки или QR, потом ещё немного.
  await page.goto(url, { waitUntil: 'load', timeout: 60000 });
  await page.waitForSelector('a[href^="bank"], canvas, svg image, .qr-img[src]', { timeout: 15000 }).catch(() => {});
  await page.waitForTimeout(2500);
  // На странице НСПК сначала экран выбора способа оплаты, список банков открывается тапом по СБП.
  if (profile.isMobile && (await page.locator('a[href^="bank"]').count()) === 0) {
    const sbp = page.getByText('Система быстрых платежей').first();
    if (await sbp.count()) {
      await sbp.click().catch(() => {});
      await page.waitForSelector('a[href^="bank"]', { timeout: 10000 }).catch(() => {});
      await page.waitForTimeout(3000);
    }
  }
  // Своя страница: на телефоне открываем QR-код кнопкой, на компьютере он виден сразу.
  const qrToggle = page.locator('[data-toggle="show-qr"]');
  if (profile.isMobile && (await qrToggle.isVisible().catch(() => false))) {
    if (process.env.E2E_SHOTS) await page.screenshot({ path: `${process.env.E2E_SHOTS}/${name}-banks.png` });
    await qrToggle.click();
    await page.waitForTimeout(800);
  }
  if (process.env.E2E_SHOTS) await page.screenshot({ path: `${process.env.E2E_SHOTS}/${name}.png`, fullPage: !profile.isMobile });
  const ownQr = await page.evaluate(() => {
    const panel = document.getElementById('qr');
    const img = panel && panel.querySelector('.qr-img');
    return panel ? { app: panel.dataset.app, drawn: Boolean(img && img.getAttribute('src')), width: img ? img.width : 0 } : null;
  });
  if (ownQr) qrData.push(ownQr.app);

  const text = (await page.locator('body').innerText()).replace(/\s+/g, ' ').trim();
  const hrefs = await page.$$eval('a[href]', (as) => as.map((a) => a.href));
  qrData.push(...(await page.evaluate(() => window.__qrData || [])));
  if (ownQr) console.log(`\n   свой QR: нарисован ${ownQr.drawn ? `да, ${ownQr.width}px` : 'нет'}`);
  const bankLinks = hrefs.filter((h) => /^bank\d+:/.test(h));
  const deepHosts = [...new Set(bankLinks.map((h) => h.split('://')[1].split(/[/?#]/)[0]))];
  const other = hrefs.filter((h) => !/^bank\d+:/.test(h));

  console.log(`\n== ${name}  ${url}`);
  console.log(`текст: ${text.slice(0, 300)}`);
  for (const line of api) console.log(`api: ${line}`);
  console.log(`диплинки bank*: ${bankLinks.length}, хосты в них: ${deepHosts.join(', ') || '—'}${bankLinks[0] ? `, пример: ${bankLinks[0]}` : ''}`);
  console.log(`логотипы банков: с origin страницы ${logos.mirror}, с других хостов ${logos.direct}`);
  if (other.length) console.log(`прочие ссылки: ${[...new Set(other)].slice(0, 6).join(' | ')}`);
  if (qrData.length) console.log(`данные QR: ${[...new Set(qrData)].join(' | ')}`);
  console.log(`мимо зеркала на хосты НУЦ: ${[...direct].join(' | ') || '—'}`);
  console.log(`ошибки запросов: ${bad.slice(0, 8).join(' | ') || '—'}`);
  console.log(`консоль: ${errors.slice(0, 5).join(' | ') || '—'}`);
  await context.close();

  if (!isMirror) return 0;
  const problems = [];
  if (direct.size || logos.direct) problems.push('запросы мимо зеркала');
  if (bad.length) problems.push('ошибки запросов');
  if (deepHosts.some((h) => h !== 'qr.nspk.ru')) problems.push('диплинк не на qr.nspk.ru');
  if (qrData.some((d) => !String(d).startsWith('https://qr.nspk.ru/'))) problems.push('QR-код не на qr.nspk.ru');
  if (problems.length) console.log(`ПРОБЛЕМЫ: ${problems.join(', ')}`);
  return problems.length;
}

(async () => {
  const browser = await chromium.launch();
  let problems = 0;
  try {
    // E2E_PROFILES=iphone,android — прогнать только часть профилей.
    const only = (process.env.E2E_PROFILES || '').split(',').filter(Boolean);
    for (const url of urls) {
      for (const [name, profile] of Object.entries(PROFILES)) {
        if (only.length && !only.includes(name)) continue;
        problems += await run(browser, url, name, profile);
      }
    }
  } finally {
    await browser.close();
  }
  process.exit(problems ? 1 : 0);
})();
