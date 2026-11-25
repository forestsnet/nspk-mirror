# nspk-mirror

Зеркало страницы оплаты СБП по QR-ссылке.

С ночи 24→25 ноября 2026 года `qr.nspk.ru` и API, к которым ходит его страница (`upc`, `widget`, `crt.cbrpay.ru`), отдают сертификаты Russian Trusted Sub CA → Russian Trusted Root CA (НУЦ Минцифры). Браузеры, кроме Яндекс Браузера, этой цепочке не доверяют, и ссылка на оплату в Safari или Chrome упирается в ошибку сертификата.

Зеркало отдаёт оплату со своего домена под обычным сертификатом. Шлюз выдаёт ссылку `https://qr.nspk.ru/<QRC_ID>?…`, проект меняет в ней хост на зеркало, и покупатель видит страницу выбора банка. Нажатие на банк открывает его приложение с уже заполненным платежом.

## Возможности

- Своя страница выбора банка: банки в порядке НСПК, логотипы через зеркало, поиск по привычным названиям, недавний банк первым.
- На компьютере QR-код для оплаты телефоном и окно с интернет-банками.
- Сумма и получатель над окном оплаты по одному ID ссылки. Proof-of-work НСПК зеркало считает само.
- Таймер жизни QR и отдельные экраны, когда платить нельзя: ссылка недействительна, время вышло, оплата уже идёт.
- Возврат в магазин после оплаты или неудачи, адрес и аватарка магазина. Всё это зашифровано в ссылке.
- Антифрод: оплата только своим мерчантам, подпись внутреннего API, лимит запросов в nginx.
- Страница НСПК целиком через прозрачный прокси как запасной вариант.
- Docker Compose с nginx в трёх режимах: HTTPS с Let's Encrypt, только HTTP за внешним прокси, за nginx хоста.

## Быстрый старт

Нужны Linux-сервер с Docker Engine 24+ и Compose v2, отдельный домен с A-записью на него и открытые 80 и 443. Ниже режим HTTPS с Let's Encrypt, он же выбран в `.env.example`. Остальные режимы описаны в [развёртывании](docs/deployment.md#режимы).

```bash
git clone https://github.com/forestsnet/nspk-mirror.git /opt/nspk-mirror
```

```bash
cd /opt/nspk-mirror && cp .env.example .env
```

В `.env` вписать свой домен в `MIRROR_DOMAIN` и `MIRROR_ORIGIN`. Пока сертификата нет, первый запуск идёт по HTTP:

```bash
COMPOSE_FILE=docker-compose.yml docker compose up -d --build
```

В `ALLOWED_MERCHANTS` стоят примеры, с ними ваши QR получат «Ссылка недействительна». Свои `merchantId` и `legalId` скрипт покажет по любой своей свежей ссылке на оплату, их нужно вписать туда:

```bash
docker compose exec -u www-data mirror php /var/www/bin/link.php <QRC_ID>
```

Выпустить сертификат и включить HTTPS, новый `ALLOWED_MERCHANTS` подхватится тут же:

```bash
docker compose run --rm certbot certonly --webroot -w /var/www/acme -d sbp.example.com -m admin@example.com --agree-tos --no-eff-email
```

```bash
docker compose up -d
```

После этого `https://sbp.example.com/_mirror/health` отвечает `ok`. Продление сертификата через cron описано в [развёртывании](docs/deployment.md#https-с-lets-encrypt), остальные переменные в [настройке](docs/configuration.md).

## Подключение

В ссылке от шлюза меняется только хост, путь и query остаются как есть:

```
https://qr.nspk.ru/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02&sum=15630&cur=RUB
https://sbp.example.com/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02&sum=15630&cur=RUB
```

Таймер и возврат в магазин включаются параметрами `mx_exp` и `mx_r`, см. [подключение проекта](docs/integration.md).

## Документация

| Раздел | О чём |
|---|---|
| [Подключение проекта](docs/integration.md) | Подмена ссылок, таймер `mx_exp`, возврат в магазин `mx_r` с примерами на PHP, Python, Node.js и Java, статус оплаты |
| [Как это работает](docs/how-it-works.md) | Страница и экраны вместо оплаты, сумма и получатель по ID, откуда берутся банки, маршруты |
| [Развёртывание](docs/deployment.md) | Требования, режимы, HTTPS с Let's Encrypt, только HTTP, за nginx хоста, обновление, проверка после выкладки |
| [Настройка](docs/configuration.md) | Переменные окружения, конфигурация nginx |
| [Безопасность](docs/security.md) | Антифрод, сертификат апстрима, сборка JS и CSS |
| [Разработка](docs/development.md) | Локальный запуск, unit-проверки, e2e в headless Chromium |
| [Неисправности](docs/troubleshooting.md) | Симптомы и что смотреть |
| [Устройство страницы НСПК](docs/nspk-page.md) | На что опирается зеркало, пригодится, когда НСПК что-то поменяет |

## Сторонние файлы

- `public/assets/fonts/` — Unbounded и Onest, SIL Open Font License (тексты лицензий рядом), подмножества кириллицы и латиницы из fontsource.
- `public/assets/qrcode.js` — qrcode-generator 1.4.4, Kazuhiko Arase, MIT.
- `public/assets/sbp-mark.svg`, `sbp-wordmark.svg` — знак и логотип СБП, товарные знаки НСПК.
- `data/c2bmembers.json` — снимок списка банков НСПК на 25.11.2026.

## Лицензия

MIT, см. [LICENSE](LICENSE). Ошибки и предложения — через [issues](https://github.com/forestsnet/nspk-mirror/issues/new/choose), об уязвимостях пишите закрыто через [приватный отчёт](https://github.com/forestsnet/nspk-mirror/security/advisories/new).
