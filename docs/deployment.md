# Развёртывание

Установка на сервер в одном из трёх режимов, обновление и проверка после выкладки.

## Требования

- Linux-сервер с Docker Engine 24+ и плагином Compose v2 (`docker compose version`).
- Отдельный домен или поддомен целиком под зеркало, A-запись на сервер. Статика НСПК раздаётся с корня, поэтому подкаталог чужого сайта не подойдёт.
- Для режима HTTPS открытые 80 и 443.
- Сервер должен открываться у покупателей из России и сам ходить на `qr.nspk.ru` и `*.cbrpay.ru`. Проверка с сервера:

```bash
curl -sk -o /dev/null -w '%{http_code}\n' https://qr.nspk.ru/proxyapp/c2bmembers.json
```

Ожидается `200`.

## Режимы

Режим задаёт `COMPOSE_FILE` в `.env`.

| Режим | `COMPOSE_FILE` | Кто держит TLS |
|---|---|---|
| HTTPS с Let's Encrypt | `docker-compose.yml:docker-compose.tls.yml` | nginx в compose, сертификат выпускает certbot из compose |
| Только HTTP | `docker-compose.yml` | Cloudflare, балансировщик или другой прокси перед сервером |
| За nginx хоста | `docker-compose.yml:docker-compose.host.yml` | nginx, который уже стоит на сервере |

Во всех режимах контейнер зеркала наружу не публикуется, до него ходит только nginx по внутренней сети compose. Исключение — режим «За nginx хоста», там зеркало слушает `127.0.0.1`.

## Установка

```bash
git clone https://github.com/forestsnet/nspk-mirror.git /opt/nspk-mirror
```

```bash
cd /opt/nspk-mirror && cp .env.example .env
```

В `.env` вписать домен, `MIRROR_ORIGIN` и режим, а в `ALLOWED_MERCHANTS` вместо примеров свои ID мерчанта, иначе ваши QR получат «Ссылка недействительна». Свои ID показывает `bin/link.php`, см. [Антифрод](security.md#антифрод). Остальные переменные описаны в [Настройке](configuration.md#переменные-окружения).

## HTTPS с Let's Encrypt

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

## Только HTTP

Для случая, когда TLS снимает кто-то перед сервером: Cloudflare в режиме проксирования, балансировщик облака, nginx на другой машине.

```bash
docker compose up -d --build
```

с `COMPOSE_FILE=docker-compose.yml` в `.env`.

- `MIRROR_ORIGIN` обязательно с `https://`. Иначе QR в режиме «Камерой телефона» и ссылки страницы НСПК получат `http://`.
- nginx берёт схему из `X-Forwarded-Proto`, если прокси его передаёт, иначе считает её `http`.
- Лимит запросов считается по адресу клиента. За Cloudflare это адреса его узлов, и лимит начнёт резать живых покупателей. Раскомментируйте `real_ip_header` и `set_real_ip_from` в `nginx/plain.conf.template` и впишите сети с <https://www.cloudflare.com/ips/>.
- Из России Cloudflare открывается нестабильно, для страницы оплаты надёжнее HTTPS на самом сервере и запись в Cloudflare без проксирования (DNS only).

## За nginx хоста

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

## Обновление

```bash
cd /opt/nspk-mirror && git pull && docker compose up -d --build
```

Кэш банков и логотипов остаётся в томе `cache`.

## Проверка после выкладки

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
