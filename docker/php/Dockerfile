# Image aplikasi Laravel: dipakai oleh service app maupun queue worker.
FROM php:8.3-cli-bookworm

# bcmath wajib ada: seluruh aritmetika uang memakainya (plan.md §44.14).
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" bcmath intl pdo_pgsql pgsql zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Dependensi di-install lebih dulu agar layer-nya dapat dipakai ulang selama
# composer.json dan composer.lock tidak berubah.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --no-autoloader --prefer-dist

COPY . .

RUN composer dump-autoload --optimize \
    && mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache \
    && chmod -R ug+rwx storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
