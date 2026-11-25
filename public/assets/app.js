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
  for (const img of document.querySelectorAll('img.avatar')) {
    img.addEventListener('error', () => img.remove(), { once: true });
  }

  const KEY = 'sbp:last-bank';
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
    const cssCell = Math.max(3, Math.floor(200 / total));
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
  const sig = body.dataset.sig;
  const ret = body.dataset.ret;
  const targets = { ok: body.dataset.retOk === '1', fail: body.dataset.retFail === '1', shop: body.dataset.retShop === '1' };

  // Возврат в магазин идёт через /r/: адреса из mx_r знает только сервер.
  function leave(to) {
    location.href = `/r/${encodeURIComponent(qrcId)}?t=${encodeURIComponent(ret)}&to=${to}`;
  }

  function countdown(root, to, seconds) {
    const el = root.querySelector('.state-countdown');
    let left = seconds;
    const tick = () => {
      if (left <= 0) {
        leave(to);
        return;
      }
      el.textContent = `Вернём в магазин через ${left} с`;
      el.hidden = false;
      left -= 1;
      setTimeout(tick, 1000);
    };
    tick();
  }

  // Экран вместо оплаты: в магазин, если он дал адрес, иначе назад по истории.
  function wireState(root) {
    const kind = root.dataset.kind;
    const to = kind === 'paid' ? 'ok' : 'fail';
    const back = root.querySelector('[data-action="back"]');
    if (back) {
      if (qrcId && ret && (targets[to] || targets.shop)) {
        back.textContent = 'Вернуться в магазин';
        back.hidden = false;
        back.addEventListener('click', () => leave(to));
      } else {
        back.hidden = history.length < 2;
        back.addEventListener('click', () => history.back());
      }
    }
    root.querySelector('[data-action="reload"]')?.addEventListener('click', () => location.reload());
    // Сами уводим только туда, куда магазин попросил вернуть именно при этом исходе.
    if (qrcId && ret && targets[to] && (kind === 'paid' || kind === 'expired')) countdown(root, to, kind === 'paid' ? 3 : 5);
  }

  const mainEl = document.querySelector('main');
  const serverState = mainEl.querySelector('.state');
  if (serverState) wireState(serverState);
  const pageContent = serverState ? null : [...mainEl.childNodes];

  const known = { amount: '', merchant: '' };
  const summaryTitle = document.querySelector('.summary h1');
  if (summaryTitle && summaryTitle.classList.contains('amount')) known.amount = summaryTitle.firstChild.textContent;
  known.merchant = document.querySelector('.merchant b')?.textContent || '';
  const avatarImg = document.querySelector('.merchant .avatar');
  let current = serverState ? serverState.dataset.kind : '';
  let started = current === 'started' || body.dataset.started === '1';
  let finished = Boolean(serverState) && !started;
  let timerHandle = 0;

  function showState(kind, code) {
    const tpl = document.getElementById(`state-${kind}`);
    if (!tpl) return false;
    if (finished || current === kind) return true;
    current = kind;
    finished = kind !== 'started';
    if (finished) clearInterval(timerHandle);
    const section = tpl.content.firstElementChild.cloneNode(true);
    const meta = section.querySelector('.state-meta');
    const text = [known.amount && `${known.amount} ₽`, known.merchant].filter(Boolean).join(' · ');
    if (text) {
      if (avatarImg) meta.append(avatarImg.cloneNode());
      meta.append(text);
      meta.hidden = false;
    }
    if (code && /^RQ\d{5}$/.test(code)) {
      const el = document.createElement('p');
      el.className = 'state-code';
      el.textContent = `Код НСПК ${code}`;
      section.append(el);
    }
    if (sheet && sheet.open) sheet.close();
    mainEl.replaceChildren(section);
    body.classList.add('is-state');
    document.title = section.querySelector('.state-title').textContent;
    wireState(section);
    window.scrollTo(0, 0);
    return true;
  }

  // Оплата не прошла, и QR снова ждёт: возвращаем страницу оплаты.
  function showPayment() {
    if (!pageContent) {
      location.reload();
      return;
    }
    current = '';
    mainEl.replaceChildren(...pageContent);
    body.classList.remove('is-state');
  }

  // Срок жизни QR приходит в mx_exp от проекта, сервер пересчитал его в секунды: часы телефона не мешают.
  const timer = document.querySelector('.timer');
  const expIn = Number(body.dataset.expIn);
  if (timer && body.dataset.expIn !== undefined && Number.isFinite(expIn) && expIn > 0) {
    const deadline = Date.now() + expIn * 1000;
    const left = timer.querySelector('.timer-left');
    const bar = timer.querySelector('.timer-bar i');
    const tick = () => {
      const sec = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
      left.textContent = `${String(Math.floor(sec / 60)).padStart(2, '0')}:${String(sec % 60).padStart(2, '0')}`;
      bar.style.transform = `scaleX(${sec / expIn})`;
      timer.classList.toggle('is-low', sec <= 60);
      if (sec === 0) showState('expired');
    };
    timer.hidden = false;
    tick();
    timerHandle = setInterval(tick, 1000);
  }

  // Статус ссылки у НСПК: раз в 30 секунд, а после тапа по банку, показа QR или возврата во вкладку раз в 4 секунды.
  let fastUntil = 0;
  let pollTimer = 0;
  function schedule() {
    clearTimeout(pollTimer);
    if (finished || !qrcId || !sig) return;
    const fast = started || Date.now() < fastUntil;
    pollTimer = setTimeout(() => {
      if (document.visibilityState === 'visible') check();
      else schedule();
    }, fast ? 4000 : 30000);
  }
  function engage() {
    fastUntil = Date.now() + 180000;
    schedule();
  }
  function check() {
    if (finished) return;
    fetch(`/api/link/${encodeURIComponent(qrcId)}?s=${encodeURIComponent(sig)}`, { headers: { Accept: 'application/json' } })
      .then((res) => (res.ok ? res.json() : null))
      .then((info) => { if (info) showLink(info); })
      .catch(() => { /* без ответа страница работает как раньше */ })
      .finally(schedule);
  }
  if (qrcId && sig && 'fetch' in window && !finished) {
    if (body.dataset.status !== 'ok' && !started) check();
    else schedule();
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState !== 'visible') return;
      engage();
      check();
    });
    grid?.addEventListener('click', (event) => { if (event.target.closest('.bank')) engage(); });
    for (const el of document.querySelectorAll('[data-toggle="show-qr"], [data-action="save-qr"], .seg')) {
      el.addEventListener('click', engage);
    }
  }

  function showLink(info) {
    if (info.status === 'ok') {
      if (started) {
        started = false;
        showPayment();
      }
      const summary = document.querySelector('.summary');
      if (!summary) return;
      if (info.amountLabel && info.amountLabel !== known.amount) {
        const amount = document.createElement('h1');
        const cur = document.createElement('span');
        amount.className = 'amount is-new';
        cur.className = 'cur';
        cur.textContent = '₽';
        amount.append(info.amountLabel, cur);
        summary.querySelector('h1').replaceWith(amount);
        document.title = `${info.amountLabel} ₽ — оплата через СБП`;
        known.amount = info.amountLabel;
      }
      if (info.merchant && info.merchant !== known.merchant) {
        const merchant = summary.querySelector('.merchant');
        (merchant.querySelector('b a') || merchant.querySelector('b')).textContent = info.merchant;
        merchant.hidden = false;
        merchant.classList.add('is-new');
        known.merchant = info.merchant;
      }
      return;
    }
    if (info.status !== 'rejected') return;
    if (info.state === 'started') {
      started = true;
      showState('started', info.code);
      return;
    }
    // НСПК не говорит «оплачено» прямо. Лучший признак: оплата началась, а потом QR перестал ждать.
    if (started && (info.state === 'expired' || info.state === 'error')) {
      showState('paid');
      return;
    }
    if ((info.state === 'expired' || info.state === 'invalid') && showState(info.state, info.code)) return;
    const alert = document.querySelector('.alert');
    if (alert && info.message) {
      alert.querySelector('.alert-text').textContent = info.message;
      alert.hidden = false;
    }
  }
})();
