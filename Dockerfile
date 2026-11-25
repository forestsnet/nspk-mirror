# 2026-11-25 — зеркало оплаты СБП на PHP + Apache
FROM php:8.4-apache

# GD с PNG и JPEG — ресайз логотипов банков (у НСПК среди .png попадаются JPEG).
# Самоподписанный сертификат — для локальной проверки по HTTPS, в проде TLS снимает nginx на хосте.
RUN apt-get update \
 && apt-get install -y --no-install-recommends libpng-dev libjpeg62-turbo-dev \
 && docker-php-ext-configure gd --with-jpeg \
 && docker-php-ext-install -j"$(nproc)" gd \
 && rm -rf /var/lib/apt/lists/* \
 && a2enmod ssl headers \
 && mkdir -p /etc/ssl/mirror /var/cache/nspk-mirror \
 && chown www-data:www-data /var/cache/nspk-mirror \
 && openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=localhost" \
      -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" \
      -keyout /etc/ssl/mirror/selfsigned.key -out /etc/ssl/mirror/selfsigned.crt 2>/dev/null

COPY docker/apache.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-mirror.ini
COPY src/ /var/www/src/
COPY data/ /var/www/data/
COPY tests/unit_test.php /var/www/tests/
COPY public/ /var/www/html/

HEALTHCHECK --interval=30s --timeout=3s --retries=3 CMD curl -fsS http://127.0.0.1/_mirror/health || exit 1
