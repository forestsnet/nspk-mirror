# Разработка

Запуск на своей машине и проверки перед коммитом.

## Локальный запуск

```bash
COMPOSE_FILE=docker-compose.yml:docker-compose.dev.yml HTTP_PORT=8080 docker compose up -d --build
```

- `http://127.0.0.1:8080/<QRC_ID>` — своя страница через nginx.
- `https://127.0.0.1:8443/<QRC_ID>` — Apache зеркала напрямую, сертификат самоподписанный. По HTTPS страница НСПК под `/nspk/` проходит свои проверки адресов (`https://` в конфиге и логотипах), по HTTP нет.

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
