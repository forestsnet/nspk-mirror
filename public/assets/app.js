(() => {
  'use strict';

  const body = document.body;
  const ua = navigator.userAgent;
  const isIOS = /iPhone|iPad|iPod/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
  const isMobile = isIOS || /Android|Mobile|HarmonyOS/i.test(ua);
  body.classList.toggle('is-mobile', isMobile);
  body.classList.toggle('is-desktop', !isMobile);
  body.classList.toggle('is-ios', isIOS);

  for (const img of document.querySelectorAll('.logo img')) {
    const broken = () => img.parentElement.classList.add('is-broken');
    if (img.complete && img.naturalWidth === 0) broken();
    else img.addEventListener('error', broken, { once: true });
  }

  const KEY = 'sbp-mirror:last-bank';
  const grid = document.querySelector('.grid');
  let last = null;
  try { last = localStorage.getItem(KEY); } catch { /* хранилище недоступно */ }
  if (grid && last && /^bank\d{12}$/.test(last)) {
    const item = grid.querySelector(`.bank[data-schema="${last}"]`);
    if (item) {
      item.classList.add('is-recent');
      grid.prepend(item);
      const img = item.querySelector('img[loading="lazy"]');
      if (img) img.loading = 'eager';
    }
  }
  grid?.addEventListener('click', (event) => {
    const item = event.target.closest('.bank');
    if (!item) return;
    try { localStorage.setItem(KEY, item.dataset.schema); } catch { /* приватный режим */ }
  });

  const ALIASES = [
    [/^т банк$/, 'тинькофф тинькоф tinkoff tbank'],
    [/сбер/, 'sber sberbank'],
    [/втб/, 'vtb'],
    [/альфа/, 'alfa alpha'],
    [/райффайзен/, 'raiffeisen райф'],
    [/газпромбанк/, 'gazprombank гпб'],
    [/озон/, 'ozon'],
    [/яндекс/, 'yandex'],
    [/совкомбанк/, 'sovcombank халва halva'],
    [/почта банк/, 'pochta'],
    [/мтс/, 'mts'],
    [/псб/, 'psb промсвязьбанк'],
    [/рсхб|россельхоз/, 'rshb россельхозбанк'],
    [/московский кредитный/, 'мкб mkb'],
    [/уралсиб/, 'uralsib'],
    [/росбанк/, 'rosbank'],
    [/вб банк|вайлдберриз/, 'wildberries wb вб'],
    [/хоум/, 'home credit хоум кредит'],
    [/русский стандарт/, 'russian standard'],
    [/ак барс/, 'ak bars акбарс'],
  ];
  const norm = (s) => s.toLowerCase().replace(/ё/g, 'е').replace(/[^a-zа-я0-9]+/g, ' ').trim();
  const items = [...document.querySelectorAll('.bank')].map((el) => {
    const name = norm(el.dataset.name);
    const extra = ALIASES.filter(([re]) => re.test(name)).map(([, words]) => words).join(' ');
    return { el, hay: `${name} ${extra}` };
  });
  const input = document.querySelector('.search input');
  const empty = document.querySelector('.empty');
  const filter = () => {
    const words = norm(input ? input.value : '').split(' ').filter(Boolean);
    const desktop = body.classList.contains('is-desktop');
    let shown = 0;
    for (const { el, hay } of items) {
      const hit = words.every((w) => hay.includes(w));
      el.classList.toggle('is-hidden', !hit);
      if (hit && (!desktop || el.dataset.web === '1')) shown += 1;
    }
    if (empty) empty.hidden = shown > 0;
  };
  input?.addEventListener('input', filter);

  const panel = document.getElementById('qr');
  const qrImg = panel?.querySelector('.qr-img');
  const save = panel?.querySelector('[data-action="save-qr"]');
  let mode = 'app';
  let drawn = '';

  // Каждый модуль занимает целое число пикселей, иначе на экране 1× края кода плывут.
  function paint(qr, cell, quiet) {
    const n = qr.getModuleCount();
    const canvas = document.createElement('canvas');
    canvas.width = canvas.height = (n + quiet * 2) * cell;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#0c0e12';
    for (let r = 0; r < n; r += 1) {
      for (let c = 0; c < n; c += 1) {
        if (qr.isDark(r, c)) ctx.fillRect((c + quiet) * cell, (r + quiet) * cell, cell, cell);
      }
    }
    return canvas.toDataURL('image/png');
  }

  function drawQr() {
    if (!panel || !qrImg || typeof qrcode !== 'function') return;
    const text = panel.dataset[mode];
    if (!text || text === drawn) return;
    const qr = qrcode(0, 'M');
    qr.addData(text);
    qr.make();
    const total = qr.getModuleCount() + 4; // по два модуля тихой зоны, остальное даёт белая рамка
    const cssCell = Math.max(3, Math.floor(224 / total));
    const scale = Math.max(1, Math.round(window.devicePixelRatio || 1));
    qrImg.src = paint(qr, cssCell * scale, 2);
    qrImg.width = cssCell * total;
    qrImg.height = cssCell * total;
    if (save) save.href = paint(qr, 16, 4);
    drawn = text;
  }

  panel?.querySelector('.seg')?.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-mode]');
    if (!tab || tab.dataset.mode === mode) return;
    mode = tab.dataset.mode;
    for (const t of panel.querySelectorAll('.seg [data-mode]')) t.setAttribute('aria-selected', String(t === tab));
    for (const list of panel.querySelectorAll('.steps')) list.hidden = list.dataset.mode !== mode;
    drawQr();
  });

  // Без поддержки <dialog> список банков раскрывается под кнопкой.
  const sheet = document.getElementById('web-banks');
  const banksPanel = document.getElementById('banks');
  const useSheet = !isMobile && Boolean(sheet && banksPanel && typeof sheet.showModal === 'function');
  if (useSheet) {
    const search = banksPanel.querySelector('.search');
    if (search) sheet.querySelector('.sheet-top').append(search);
    sheet.append(banksPanel);
    sheet.addEventListener('click', (event) => {
      const r = sheet.getBoundingClientRect();
      const outside = event.target === sheet
        && (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom);
      if (outside || event.target.closest('[data-close]')) sheet.close();
    });
    sheet.addEventListener('close', () => document.documentElement.classList.remove('is-locked'));
  }

  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  for (const btn of document.querySelectorAll('[data-toggle]')) {
    if (useSheet && btn.dataset.toggle === 'show-banks') {
      btn.removeAttribute('aria-expanded');
      btn.setAttribute('aria-haspopup', 'dialog');
      btn.setAttribute('aria-controls', sheet.id);
    }
    btn.addEventListener('click', () => {
      if (useSheet && btn.dataset.toggle === 'show-banks') {
        filter();
        document.documentElement.classList.add('is-locked');
        sheet.showModal();
        sheet.scrollTop = 0;
        input?.focus();
        return;
      }
      const on = body.classList.toggle(btn.dataset.toggle);
      btn.setAttribute('aria-expanded', String(on));
      btn.querySelector('span').textContent = on ? btn.dataset.on : btn.dataset.off;
      if (!on) return;
      if (btn.dataset.toggle === 'show-qr') drawQr();
      if (btn.dataset.toggle === 'show-banks') filter();
      document.getElementById(btn.getAttribute('aria-controls'))
        ?.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
    });
  }

  if (!isMobile) drawQr();

  const qrcId = body.dataset.qrc;
  if (qrcId && 'fetch' in window) {
    fetch(`/api/link/${encodeURIComponent(qrcId)}`, { headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? res.json() : null))
      .then((info) => { if (info) showLink(info); })
      .catch(() => { /* без суммы страница работает как раньше */ });
  }

  function showLink(info) {
    const head = document.querySelector('.head');
    if (info.status === 'ok') {
      const h1 = head.querySelector('h1');
      const same = h1.classList.contains('amount') && h1.firstChild && h1.firstChild.textContent === info.amountLabel;
      if (info.amountLabel && !same) {
        const amount = document.createElement('h1');
        const cur = document.createElement('span');
        amount.className = 'amount is-new';
        cur.className = 'cur';
        cur.textContent = '₽';
        amount.append(info.amountLabel, cur);
        h1.replaceWith(amount);
        document.title = `${info.amountLabel} ₽ — оплата через СБП`;
      }
      if (info.merchant) {
        const merchant = head.querySelector('.merchant');
        merchant.querySelector('b').textContent = info.merchant;
        merchant.hidden = false;
        merchant.classList.add('is-new');
      }
      return;
    }
    if (info.status === 'rejected' && info.message) {
      const alert = document.querySelector('.alert');
      alert.querySelector('.alert-text').textContent = info.message;
      alert.querySelector('.alert-hint').hidden = !info.expired;
      alert.hidden = false;
      body.classList.toggle('is-expired', Boolean(info.expired));
    }
  }
})();
