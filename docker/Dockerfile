# ─────────────────────────────────────────────────────────────
# Code.getxtra.in — PHP-FPM image (serves both web and worker roles)
# ─────────────────────────────────────────────────────────────
FROM php:8.3-fpm-alpine AS base

# System deps for common extensions.
RUN apk add --no-cache \
        git \
        unzip \
        icu-dev \
        libzip-dev \
        oniguruma-dev \
    && docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        intl \
        zip \
        opcache \
    && apk del --no-cache git

# Composer (multi-stage copy from official image).
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/app

# Install PHP dependencies first (better layer caching).
COPY composer.json composer.lock* ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-autoloader || true

# Copy application source.
COPY . .
RUN composer dump-autoload --optimize --no-dev || true

# Recommended production PHP settings.
RUN { \
        echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=0'; \
        echo 'opcache.memory_consumption=128'; \
        echo 'opcache.max_accelerated_files=10000'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'expose_php=0'; \
        echo 'upload_max_filesize=100M'; \
        echo 'post_max_size=105M'; \
    } > /usr/local/etc/php/conf.d/zz-app.ini

# Writable runtime dirs.
RUN mkdir -p storage/logs storage/cache storage/tmp \
    && chown -R www-data:www-data storage

USER www-data

EXPOSE 9000
CMD ["php-fpm"]
