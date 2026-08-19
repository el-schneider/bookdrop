# syntax=docker/dockerfile:1

FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --no-scripts --optimize-autoloader
COPY . .
RUN mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache \
    && composer dump-autoload --no-dev --no-interaction --optimize

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY resources ./resources
COPY public ./public
COPY vite.config.js tailwind.config.js postcss.config.js ./
RUN npm run build

FROM php:8.4-apache AS app

# Kepubify converts EPUB to Kobo's KEPUB. Without it the device tracks progress only per chapter
# and cannot create highlights at all on synced books. Statically linked Go binary, no runtime deps.
# Pinned and checksummed: this runs in the production image.
ARG KEPUBIFY_VERSION=v4.0.4
ARG KEPUBIFY_SHA256=37d7628d26c5c906f607f24b36f781f306075e7073a6fe7820a751bb60431fc5
ADD --chmod=755 https://github.com/pgaskin/kepubify/releases/download/${KEPUBIFY_VERSION}/kepubify-linux-64bit /usr/local/bin/kepubify
# Exit codes below 126 mean the binary actually ran; 126/127 would mean wrong architecture or
# missing. Checked this way rather than via a specific flag, whose name is not guaranteed.
RUN echo "${KEPUBIFY_SHA256}  /usr/local/bin/kepubify" | sha256sum -c - \
    && (kepubify --help >/dev/null 2>&1; [ $? -lt 126 ] || (echo 'kepubify did not execute' >&2; exit 1))

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libzip-dev \
        unzip \
    && docker-php-ext-install zip \
    && a2enmod rewrite \
    && { \
        echo 'upload_max_filesize=128M'; \
        echo 'post_max_size=128M'; \
        echo 'memory_limit=256M'; \
    } > /usr/local/etc/php/conf.d/bookdrop.ini \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public \
    APP_ENV=production \
    APP_DEBUG=false \
    DB_CONNECTION=sqlite \
    DB_DATABASE=/data/database.sqlite \
    BOOKDROP_STORAGE_PATH=/data \
    BOOKDROP_BOOKS_PATH=books \
    LOG_CHANNEL=stderr \
    LOG_LEVEL=warning

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}/../!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && sed -ri -e 's!CustomLog \$\{APACHE_LOG_DIR\}/access.log combined!CustomLog ${APACHE_LOG_DIR}/access.log bookdrop_redacted!g' /etc/apache2/sites-available/*.conf \
    && printf '%s\n' 'LogFormat "%h %l %u %t %m [redacted] %H %>s %O" bookdrop_redacted' > /etc/apache2/conf-available/bookdrop-logs.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/bookdrop.conf \
    && a2enconf bookdrop bookdrop-logs

WORKDIR /var/www/html
COPY --from=vendor /app ./
COPY --from=assets /app/public/build ./public/build
COPY docker/entrypoint.sh /usr/local/bin/bookdrop-entrypoint
RUN chmod +x /usr/local/bin/bookdrop-entrypoint \
    && chown -R www-data:www-data storage bootstrap/cache public/build

EXPOSE 80
ENTRYPOINT ["bookdrop-entrypoint"]
CMD ["apache2-foreground"]
