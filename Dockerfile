# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader
COPY . .
RUN composer dump-autoload --no-dev --no-interaction --optimize

FROM node:25-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build

FROM php:8.4-apache AS app

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libcurl4-openssl-dev \
        libonig-dev \
        libsqlite3-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
        zlib1g-dev \
    && docker-php-ext-install \
        curl \
        dom \
        mbstring \
        pdo_sqlite \
        sqlite3 \
        xml \
        zip \
    && a2enmod rewrite \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    BOOKDROP_STORAGE_PATH=/data \
    BOOKDROP_BOOKS_PATH=books \
    LOG_CHANNEL=stderr

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/../!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/bookdrop.conf \
    && a2enconf bookdrop

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/bookdrop-entrypoint
RUN chmod +x /usr/local/bin/bookdrop-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache public/build

EXPOSE 80
ENTRYPOINT ["bookdrop-entrypoint"]
CMD ["apache2-foreground"]
