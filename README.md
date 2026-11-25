# nspk-mirror

Зеркало страницы оплаты СБП по QR-ссылке.

С ночи 24→25 ноября 2026 года `qr.nspk.ru` и API, к которым ходит его страница (`upc`, `widget`, `crt.cbrpay.ru`), отдают сертификаты Russian Trusted Sub CA → Russian Trusted Root CA (НУЦ Минцифры). Браузеры, кроме Яндекс Браузера, этой цепочке не доверяют, и ссылка на оплату в Safari или Chrome упирается в ошибку сертификата.

Зеркало отдаёт оплату со своего домена под обычным сертификатом. Шлюз выдаёт ссылку `https://qr.nspk.ru/<QRC_ID>?…`, проект меняет в ней хост на зеркало, и покупатель видит страницу выбора банка. Нажатие на банк открывает его приложение с уже заполненным платежом.

## Содержание

- [Как это работает](#как-это-работает)
- [Развёртывание](#развёртывание)
  - [Требования](#требования)
  - [Режимы](#режимы)
  - [Установка](#установка)
  - [HTTPS с Let's Encrypt](#https-с-lets-encrypt)
  - [Только HTTP](#только-http)
  - [За nginx хоста](#за-nginx-хоста)
  - [Переменные окружения](#переменные-окружения)
  - [Обновление](#обновление)
  - [Проверка после выкладки](#проверка-после-выкладки)
  - [Локальная разработка](#локальная-разработка)
- [Конфигурация nginx](#конфигурация-nginx)
- [Проверки](#проверки)
- [Неисправности](#неисправности)
- [Устройство страницы НСПК](#устройство-страницы-нспк)
- [Сторонние файлы](#сторонние-файлы)

## Как это работает

### Подмена ссылок

В ссылке от шлюза меняется только начало `https://qr.nspk.ru/` на адрес зеркала. Путь и query сохраняются как есть, в них может быть `crc` статического QR.

```
https://qr.nspk.ru/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02&sum=15630&cur=RUB
https://sbp.example.com/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02&sum=15630&cur=RUB
```

В QR-картинку, которую проект рисует сам, кладите исходную ссылку НСПК. Встроенные сканеры приложений банков узнают платёж по `qr.nspk.ru`.

### Страница

На телефоне первой идёт сетка банков в порядке НСПК (популярные сверху), с поиском и банком, который выбирали в прошлый раз, на первом месте. Ниже кнопка «Показать QR-код», QR можно сохранить картинкой и загрузить в приложение банка.

На компьютере первым идёт QR-код. Режим «Приложением банка» кодирует исходную ссылку НСПК, «Камерой телефона» кодирует адрес этой же страницы на зеркале. Кнопка «Оплатить в интернет-банке» открывает модалку с банками, у которых есть веб-версия.

Ссылки в банки строятся так же, как на странице НСПК. Если у банка включён веб-клиент, ссылка ведёт на него (Т-Банк, ВТБ, Альфа и ещё около 65 банков), иначе на схему приложения `bankXXXXXXXXXXXX://qr.nspk.ru/<QRC_ID><query>`. Хост в диплинке всегда `qr.nspk.ru`, другой приложения банков не примут. Сумма в шапке показывается, когда в ссылке есть `sum` в копейках.

### Маршруты

| Путь | Что отдаёт |
|---|---|
| `/<QRC_ID>` | Свою страницу выбора банка |
| `/logo/{40,80,120}/bankXXXXXXXXXXXX.png` | Логотипы банков точно под плитку для экранов ×1, ×2, ×3 |
| `/nspk/<QRC_ID>` | Страницу НСПК целиком, запасной вариант. Ссылка на неё есть в блоке «Не работает оплата?» |
| `/proxyapp/…`, `/static/…`, `/js/…`, `/woff2/…` | Статику НСПК через прокси, картинки и шрифты из дискового кэша |
| `/_cbr/{upc,widget,crt}/…` | API cbrpay.ru для страницы НСПК |
| `/_mirror/health` | `ok`, апстрим не трогает |
| `/`, `/serviceWorker.js` | 404. Service worker НСПК на корне перехватывал бы `/<QRC_ID>`, поэтому он есть только под `/nspk/` |

### Откуда берутся банки

1. `https://widget.cbrpay.ru/v2/sbp/members/qr` с заголовком `X-CLIENT-ID: 000000000000`, тот же API, что у страницы НСПК.
2. Если он не ответил, `https://qr.nspk.ru/proxyapp/c2bmembers.json`. У ВТБ там устаревший id без логотипа, в остальном ссылки те же.
3. Если не ответил и он, снимок `data/c2bmembers.json` из репозитория.

Список кэшируется на 10 минут, логотипы на неделю, остальная статика НСПК на сутки. Если апстрим не отвечает, отдаётся протухшая копия. Кэш лежит в томе `cache` и переживает пересборку.

### Проверка сертификата апстрима

Цепочку Russian Trusted Root CA зеркало сознательно не проверяет (`CURLOPT_SSL_VERIFYPEER=false`), корень Минцифры никуда не добавляется. Цена решения: тот, кто встанет между зеркалом и НСПК, сможет подменить список банков. Поэтому в `href` попадают только два вида ссылок, схема приложения `bankXXXXXXXXXXXX://qr.nspk.ru/{QRC_ID}…` и `https://…` с `{QRC_ID}`, остальное отбрасывается.

## Развёртывание

### Требования

- Linux-сервер с Docker Engine 24+ и плагином Compose v2 (`docker compose version`).
- Отдельный домен или поддомен целиком под зеркало, A-запись на сервер. Статика НСПК раздаётся с корня, поэтому подкаталог чужого сайта не подойдёт.
- Для режима HTTPS открытые 80 и 443.
- Сервер должен открываться у покупателей из России и сам ходить на `qr.nspk.ru` и `*.cbrpay.ru`. Проверка с сервера:

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://qr.nspk.ru/proxyapp/c2bmembers.json
```

Ожидается `200`.

### Режимы

Режим задаёт `COMPOSE_FILE` в `.env`.

| Режим | `COMPOSE_FILE` | Кто держит TLS |
|---|---|---|
| HTTPS с Let's Encrypt | `docker-compose.yml:docker-compose.tls.yml` | nginx в compose, сертификат выпускает certbot из compose |
| Только HTTP | `docker-compose.yml` | Cloudflare, балансировщик или другой прокси перед сервером |
| За nginx хоста | `docker-compose.yml:docker-compose.host.yml` | nginx, который уже стоит на сервере |

Во всех режимах контейнер зеркала наружу не публикуется, до него ходит только nginx по внутренней сети compose. Исключение — режим «За nginx хоста», там зеркало слушает `127.0.0.1`.

### Установка

```bash
git clone git@github.com:forestsnet/nspk-mirror.git /opt/nspk-mirror
```

```bash
cd /opt/nspk-mirror && cp .env.example .env
```

В `.env` вписать домен, `MIRROR_ORIGIN` и режим, остальное по необходимости (см. [переменные](#переменные-окружения)).

### HTTPS с Let's Encrypt

Сертификат выпускается через webroot: nginx отдаёт проверочные файлы certbot из общего тома. Пока сертификата нет, nginx с TLS не стартует, поэтому первый запуск идёт по HTTP.

1. В `.env` указать `MIRROR_DOMAIN`, `MIRROR_ORIGIN=https://<домен>` и `COMPOSE_FILE=docker-compose.yml:docker-compose.tls.yml`.

2. Поднять зеркало и nginx по HTTP:

```bash
COMPOSE_FILE=docker-compose.yml docker compose up -d --build
```

3. Выпустить сертификат:

```bash
docker compose run --rm certbot certonly --webroot -w /var/www/acme -d sbp.example.com -m admin@example.com --agree-tos --no-eff-email
```

Сертификат ляжет в `./letsencrypt/live/sbp.example.com/`.

4. Включить HTTPS. Команда пересоздаст nginx с TLS-шаблоном из `.env`, зеркало не перезапустится:

```bash
docker compose up -d
```

5. Продление. Certbot сам пропускает сертификаты, которым до конца больше 30 дней, так что запускать можно дважды в сутки. Строка для `crontab -e` от root:

```
17 3,15 * * * cd /opt/nspk-mirror && /usr/bin/docker compose run --rm certbot renew --quiet && /usr/bin/docker compose exec -T nginx nginx -s reload
```

Если на сервере уже работает certbot хоста, укажите `LETSENCRYPT_DIR=/etc/letsencrypt` и пропустите шаги 2–3. Тогда продлевает хост, а nginx в compose нужно перечитывать после продления (`deploy-hook`).

### Только HTTP

Для случая, когда TLS снимает кто-то перед сервером: Cloudflare в режиме проксирования, балансировщик облака, nginx на другой машине.

```bash
docker compose up -d --build
```

с `COMPOSE_FILE=docker-compose.yml` в `.env`.

- `MIRROR_ORIGIN` обязательно с `https://`. Иначе QR в режиме «Камерой телефона» и ссылки страницы НСПК получат `http://`.
- nginx берёт схему из `X-Forwarded-Proto`, если прокси его передаёт, иначе считает её `http`.
- Лимит запросов считается по адресу клиента. За Cloudflare это адреса его узлов, и лимит начнёт резать живых покупателей. Раскомментируйте `real_ip_header` и `set_real_ip_from` в `nginx/plain.conf.template` и впишите сети с <https://www.cloudflare.com/ips/>.
- Из России Cloudflare открывается нестабильно, для страницы оплаты надёжнее HTTPS на самом сервере и запись в Cloudflare без проксирования (DNS only).

### За nginx хоста

Если на сервере уже есть nginx на 80 и 443, nginx в compose не нужен. В `.env`:

```
COMPOSE_FILE=docker-compose.yml:docker-compose.host.yml
MIRROR_PORT=8080
```

```bash
docker compose up -d --build
```

Зеркало слушает `127.0.0.1:8080`, nginx из compose не запускается. Конфиг для nginx хоста с HTTPS (сертификат от certbot хоста):

```nginx
limit_req_zone $binary_remote_addr zone=sbp_mirror:10m rate=10r/s;

server {
    listen 80;
    server_name sbp.example.com;

    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    location / {
        return 301 https://$host$request_uri;
    }
}

server {
    listen 443 ssl;
    http2 on;
    server_name sbp.example.com;

    ssl_certificate     /etc/letsencrypt/live/sbp.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/sbp.example.com/privkey.pem;
    add_header Strict-Transport-Security "max-age=31536000" always;

    client_max_body_size 64k;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Host $http_host;
    proxy_set_header X-Forwarded-Proto https;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_read_timeout 30s;

    location ~ ^/(?:nspk/)?(?:assets|logo|proxyapp/logo|proxyapp/uniqr|static|woff2)/ {
        proxy_pass http://127.0.0.1:8080;
    }

    location / {
        limit_req zone=sbp_mirror burst=60 nodelay;
        limit_req_status 429;
        proxy_pass http://127.0.0.1:8080;
    }
}
```

На nginx старше 1.25.1 вместо `http2 on;` пишется `listen 443 ssl http2;`.

Без TLS на хосте (его снимает прокси перед сервером) остаётся один `server` на 80 с теми же `proxy_*` и `location`, а `X-Forwarded-Proto` передаётся от прокси:

```nginx
server {
    listen 80;
    server_name sbp.example.com;

    # ... те же client_max_body_size, proxy_* и location, что выше, кроме строки ниже
    proxy_set_header X-Forwarded-Proto $http_x_forwarded_proto;
}
```

### Переменные окружения

| Переменная | По умолчанию | Назначение |
|---|---|---|
| `COMPOSE_FILE` | `docker-compose.yml` | Режим развёртывания, см. [режимы](#режимы) |
| `MIRROR_DOMAIN` | `_` | Домен зеркала, подставляется в `server_name` и путь к сертификату |
| `MIRROR_ORIGIN` | пусто | Адрес зеркала, на него переписываются ссылки страницы НСПК и QR в режиме «Камерой телефона». Пусто — берётся из `Host`, `X-Forwarded-Host` и `X-Forwarded-Proto` |
| `HTTP_PORT` | `80` | Порт nginx на хосте для HTTP |
| `HTTPS_PORT` | `443` | Порт nginx на хосте для HTTPS |
| `LETSENCRYPT_DIR` | `./letsencrypt` | Каталог сертификатов, свой или `/etc/letsencrypt` хоста |
| `MIRROR_PORT` | `8080` | Порт зеркала на `127.0.0.1` в режиме «За nginx хоста» |

### Обновление

```bash
cd /opt/nspk-mirror && git pull && docker compose up -d --build
```

Кэш банков и логотипов остаётся в томе `cache`.

### Проверка после выкладки

```bash
curl -s https://sbp.example.com/_mirror/health
```

Ответ `ok`. Дальше откройте на телефоне `https://sbp.example.com/<QRC_ID>` со свежей ссылкой от шлюза и нажмите на свой банк.

```bash
docker compose logs -f --tail=100 mirror nginx
```

```bash
docker compose exec mirror php /var/www/tests/unit_test.php
```

### Локальная разработка

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.dev.yml HTTP_PORT=8080 docker compose up -d --build
```

- `http://127.0.0.1:8080/<QRC_ID>` — своя страница через nginx.
- `https://127.0.0.1:8443/<QRC_ID>` — Apache зеркала напрямую, сертификат самоподписанный. По HTTPS страница НСПК под `/nspk/` проходит свои проверки адресов (`https://` в конфиге и логотипах), по HTTP нет.

## Конфигурация nginx

| Файл | Что внутри |
|---|---|
| `nginx/plain.conf.template` | HTTP на 80, проверки Let's Encrypt, схема из `X-Forwarded-Proto`, заготовка `real_ip` для Cloudflare |
| `nginx/tls.conf.template` | Редирект с 80 на HTTPS, проверки Let's Encrypt, HTTPS с HTTP/2 и HSTS |
| `nginx/mirror.conf` | Общая часть: заголовки к зеркалу, лимит запросов, статика без лимита |

Шаблоны обрабатывает envsubst официального образа nginx, подставляется только `${MIRROR_DOMAIN}`. Адрес зеркала nginx берёт у DNS Docker (`resolver 127.0.0.11`), поэтому пересоздание контейнера зеркала не требует перезапуска nginx.

Лимит: 10 запросов в секунду на адрес с запасом 60, сверх него ответ 429. Под лимит не попадают логотипы, шрифты и своя статика страницы, их на одну страницу до двух сотен.

## Проверки

```bash
docker compose exec mirror php /var/www/tests/unit_test.php
```

Маршрутизация, переписывание адресов, разбор списков банков, отсев опасных ссылок, сборка диплинков, рендер страницы, ресайз логотипа.

`tests/e2e.cjs` прогоняет страницу в headless Chromium как iPhone, Android и компьютер. Показывает текст, ответы payment-link, запросы в обход зеркала, ошибки, диплинки и данные QR. Нужен пакет `playwright-core` с его Chromium:

```bash
npm i --no-save playwright-core && npx playwright-core install chromium
```

```bash
node tests/e2e.cjs https://127.0.0.1:8443/<QRC_ID> https://127.0.0.1:8443/nspk/<QRC_ID>
```

`E2E_PROFILES=iphone,desktop` гоняет часть профилей, `E2E_SHOTS=<каталог>` сохраняет скриншоты. Для сравнения с оригиналом можно передать `https://qr.nspk.ru/<QRC_ID>`.

## Неисправности

| Симптом | Что смотреть |
|---|---|
| nginx в режиме HTTPS не стартует, в логе `cannot load certificate` | Нет сертификата в `LETSENCRYPT_DIR/live/<домен>/`. Выпустите его по шагам 2–3 |
| 502 на странице или на `/nspk/` | `docker compose logs mirror`, строки `nspk-mirror: GET …`. Сервер не достучался до НСПК, проверьте `curl` из [требований](#требования) |
| На плитке буква вместо логотипа | Логотипа нет у самой НСПК или он не скачался. Заголовок `X-Mirror-Cache` у `/logo/40/<файл>` показывает, откуда ответ |
| Банк пишет «QR-код недействителен» | Динамический QR живёт ограниченное время, нужен новый платёж. На `/nspk/` это видно как `RQ05014 «Значение uniQrId не найдено»` |
| Покупатели получают 429 | Зеркало за прокси, и лимит считается по его адресам. Настройте `real_ip`, см. [Только HTTP](#только-http) |
| Сломалась страница `/nspk/` | НСПК обновила свою страницу. Прогоните e2e на `https://qr.nspk.ru/<QRC_ID>` и на зеркало и сравните, ориентиры ниже |

## Устройство страницы НСПК

Пригодится, когда НСПК что-то поменяет.

- Статика (`js/index.js`, `proxyapp/*.json`, шрифты) грузится с базы `"https://qr.nspk.ru/"` со слэшем, её зеркало переписывает на себя.
- Хост диплинка берётся из литерала `px="https://qr.nspk.ru"` без слэша через `El(document.URL, px)`. Его переписывать нельзя, поэтому `https://qr.nspk.ru` без слэша остаётся как есть.
- Данные платежа: `GET upc.cbrpay.ru/v1/payment-link/<QRC_ID>` с proof-of-work в заголовках `X-Token-*`, их считает браузер. Без них ответ 403.
- Список банков: `widget.cbrpay.ru/v2/{sbp,dr}/members/qr` с `X-CLIENT-ID: 000000000000`, без него 400.
- `apiConfig.json` → `certBanner`: страница дёргает `crt.cbrpay.ru/check` и при ошибке просит поставить сертификат Минцифры. Через зеркало проверка проходит, баннера нет.
- На хостах с `localhost` в имени страница берёт статику с `/qr/`, зеркало понимает и этот префикс.

## Сторонние файлы

- `public/assets/fonts/` — Unbounded и Onest, SIL Open Font License (тексты лицензий рядом), подмножества кириллицы и латиницы из fontsource.
- `public/assets/qrcode.js` — qrcode-generator 1.4.4, Kazuhiko Arase, MIT.
- `public/assets/sbp-mark.svg`, `sbp-wordmark.svg` — знак и логотип СБП, товарные знаки НСПК.
- `data/c2bmembers.json` — снимок списка банков НСПК на 25.11.2026.
