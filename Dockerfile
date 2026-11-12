FROM php:8.4-apache-bookworm AS dependencies
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /build
RUN apt-get update && apt-get install -y --no-install-recommends git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY composer.json composer.lock ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist \
    --no-interaction --no-progress --no-scripts --optimize-autoloader

FROM php:8.4-apache-bookworm
WORKDIR /var/www/html
COPY --from=dependencies /build/vendor ./vendor
COPY bootstrap.php index.php proxy.php map.js map.css favicon.ico ./
COPY icons/ ./icons/
COPY deploy/apache-app.conf /etc/apache2/sites-available/000-default.conf
COPY deploy/php.ini /usr/local/etc/php/conf.d/mapa.ini
COPY deploy/healthcheck.php /usr/local/lib/mapa/healthcheck.php
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD ["php", "/usr/local/lib/mapa/healthcheck.php"]
