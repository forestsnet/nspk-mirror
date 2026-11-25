# Подключение проекта

Как проекту, который выдаёт покупателям ссылки на оплату, перевести их на зеркало, включить таймер и возврат в магазин.

## Подмена ссылок

В ссылке от шлюза меняется только начало `https://qr.nspk.ru/` на адрес зеркала. Путь и query сохраняются как есть, в них может быть `crc` статического QR.

```
https://qr.nspk.ru/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02&sum=15630&cur=RUB
https://sbp.example.com/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?type=02&sum=15630&cur=RUB
```

В QR-картинку, которую проект рисует сам, кладите исходную ссылку НСПК. Встроенные сканеры приложений банков узнают платёж по `qr.nspk.ru`.

## Параметры ссылки для зеркала

Параметры с префиксом `mx_` читает только зеркало, в ссылки в банки и на страницу НСПК они не попадают. Все необязательны.

| Параметр | Что делает |
|---|---|
| `mx_exp` | Unix-время, когда QR перестанет действовать. Страница показывает «Оплатите в течение 04:59» с полосой, на нуле меняет экран на «Время на оплату вышло». Уже прошедший срок сразу даёт этот экран |
| `mx_r` | Зашифрованные адреса: куда вернуть при успехе и при неуспехе, адрес магазина и его аватарка, см. [Возврат в магазин](#возврат-в-магазин) |

НСПК срок жизни QR не отдаёт, его знает шлюз или проект при создании платежа:

```
https://sbp.example.com/BD10103IQ18O3ISP8RGAM6FN3AP1N0GH?mx_exp=1795571200&mx_r=64QdKm1I8YFvXEGT…
```

Отсчёт идёт от секунд, которые посчитал сервер, поэтому неточные часы телефона его не сбивают.

## Возврат в магазин

`mx_r` — четыре необязательных адреса, зашифрованных ключом `RETURN_KEY`. Ключ один на зеркало и проекты, которые собирают ссылки:

```bash
openssl rand -hex 32
```

| Поле | Зачем |
|---|---|
| `ok` | Куда вернуть после оплаты |
| `fail` | Куда вернуть, если QR истёк или банк отклонил оплату |
| `shop` | Адрес магазина. Имя получателя становится ссылкой, и туда ведёт «Вернуться в магазин», если нужного из двух адресов выше нет |
| `avatar` | Картинка магазина в плашке получателя: PNG, JPEG или GIF. Зеркало скачивает её само, с проверкой сертификата, и ужимает под 24, 48 и 72 px |

Формат: `base64url(nonce ‖ шифртекст ‖ тег)` без `=`, AES-256-GCM, nonce 12 байт, тег 16 байт, дополнительные данные — QRC_ID ссылки. Открытый текст — поля `ok`, `fail`, `shop`, `avatar` через перевод строки, пустое поле остаётся пустой строкой, хвост из пустых можно опустить. Из-за QRC_ID в дополнительных данных токен от одной ссылки не подойдёт к другой. Адреса не попадают в HTML: страница знает только, какие из них есть, а уводит через `/r/<QRC_ID>`, который сам расшифровывает токен и отвечает 303.

PHP:

```php
function mx_r(string $qrcId, string $keyHex, array $f): string
{
    $plain = rtrim(implode("\n", [$f['ok'] ?? '', $f['fail'] ?? '', $f['shop'] ?? '', $f['avatar'] ?? '']), "\n");
    $nonce = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', hex2bin($keyHex), OPENSSL_RAW_DATA, $nonce, $tag, $qrcId, 16);
    return rtrim(strtr(base64_encode($nonce . $ct . $tag), '+/', '-_'), '=');
}
```

Python, пакет `cryptography`:

```python
import base64, os
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

def mx_r(qrc_id: str, key_hex: str, ok="", fail="", shop="", avatar="") -> str:
    plain = "\n".join([ok, fail, shop, avatar]).rstrip("\n").encode()
    nonce = os.urandom(12)
    sealed = AESGCM(bytes.fromhex(key_hex)).encrypt(nonce, plain, qrc_id.encode())
    return base64.urlsafe_b64encode(nonce + sealed).rstrip(b"=").decode()
```

Node.js:

```js
const crypto = require('crypto');

function mxR(qrcId, keyHex, { ok = '', fail = '', shop = '', avatar = '' } = {}) {
  const nonce = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv('aes-256-gcm', Buffer.from(keyHex, 'hex'), nonce);
  cipher.setAAD(Buffer.from(qrcId));
  const plain = [ok, fail, shop, avatar].join('\n').replace(/\n+$/, '');
  const ct = Buffer.concat([cipher.update(plain, 'utf8'), cipher.final()]);
  return Buffer.concat([nonce, ct, cipher.getAuthTag()]).toString('base64url');
}
```

Java:

```java
static String mxR(String qrcId, byte[] key, String ok, String fail, String shop, String avatar) throws Exception {
    String plain = String.join("\n", ok, fail, shop, avatar).replaceAll("\n+$", "");
    byte[] nonce = new byte[12];
    new SecureRandom().nextBytes(nonce);
    Cipher cipher = Cipher.getInstance("AES/GCM/NoPadding");
    cipher.init(Cipher.ENCRYPT_MODE, new SecretKeySpec(key, "AES"), new GCMParameterSpec(128, nonce));
    cipher.updateAAD(qrcId.getBytes(StandardCharsets.UTF_8));
    byte[] sealed = cipher.doFinal(plain.getBytes(StandardCharsets.UTF_8));
    byte[] token = ByteBuffer.allocate(12 + sealed.length).put(nonce).put(sealed).array();
    return Base64.getUrlEncoder().withoutPadding().encodeToString(token);
}
```

Проверить ключ и адреса без своего кода:

```bash
docker compose exec mirror php /var/www/bin/return-token.php <QRC_ID> 'ok=https://shop.example/ok?o=42' 'fail=https://shop.example/fail' 'shop=https://shop.example' 'avatar=https://shop.example/logo.png'
```

Токен на все четыре адреса занимает около 190 символов.

## Статус оплаты

Явного «оплачено» НСПК не отдаёт: в ответе по QR есть сумма, вывеска и ID мерчанта, а статуса платежа нет. Страница следит за тем, что НСПК всё-таки сообщает:

| Что видит страница | Экран | Возврат |
|---|---|---|
| «оплата уже началась» (RQ05301, RQ05022) | «Оплата уже идёт», проверка продолжается | нет |
| после этого QR перестал ждать оплаты | «Оплата завершена» | на `ok` через 3 секунды |
| QR снова ждёт оплаты | возвращается страница оплаты | нет |
| QR истёк без начатой оплаты или вышел `mx_exp` | «Время на оплату вышло» | на `fail` через 5 секунд |

Если нужного адреса нет, кнопка «Вернуться в магазин» ведёт на `shop`, а без `shop` остаётся «Вернуться назад» по истории браузера.

Страница успеха в магазине обязана сама проверить статус заказа. «Оплата завершена» здесь вывод из кодов НСПК, а не подтверждение банка, и адрес `ok` можно открыть руками. Каждая смена кода по QR пишется в лог строкой `link <QRC_ID> RQ00000 → RQ05301`, по ней видно, как НСПК отражает настоящие платежи.
