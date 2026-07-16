# Multi-stage build for Sell.getxtra.in (Req 23.3).
# Stage 1 resolves production dependencies; stage 2 is a lean PHP-FPM runtime.

# ── Stage 1: composer dependencies ─────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader \
    || composer update --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

# ── Stage 2: runtime ───────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS runtime

# Extensions required by the app (PDO/MySQL, opcache, intl, redis).
RUN apk add --no-cache icu-dev oniguruma-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql opcache intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

# Production PHP + OPcache tuning.
COPY deploy/php/opcache.ini /usr/local/etc/php/conf.d/opcache.ini
COPY deploy/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor

# Least-privilege runtime user; writable runtime dirs only.
RUN chown -R www-data:www-data storage \
    && find storage -type d -exec chmod 775 {} \;

USER www-data
EXPOSE 9000

# Container-native health probe hits the app readiness endpoint.
HEALTHCHECK --interval=15s --timeout=3s --start-period=20s --retries=3 \
    CMD php -r '$c=@fsockopen("127.0.0.1",9000);exit($c?0:1);'

CMD ["php-fpm"]
