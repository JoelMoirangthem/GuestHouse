# --- Guest House Booking System — production image for Render ---------------
# Multi-stage: build front-end assets with Node, then run on PHP 8.2 + Apache.

# 1) Build CSS/JS with Vite
FROM node:20-alpine AS assets
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
# tailwind scans blade files, so they must be present at build time
COPY . .
RUN npm run build

# 2) PHP runtime
FROM php:8.2-apache

# System libs for the required PHP extensions (gd, pdo_mysql, mbstring, zip).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpng-dev libjpeg-dev libfreetype6-dev libzip-dev zip unzip git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd pdo_mysql mbstring zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Apache: serve from Laravel's public/ and allow .htaccess rewrites.
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf \
    && a2enmod rewrite

WORKDIR /var/www/html

# Install PHP dependencies first (better layer caching).
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

# App source
COPY . .

# Bring in the compiled front-end assets from the Node stage.
COPY --from=assets /app/public/build ./public/build

# Finish composer (autoloader + scripts) now that all files are present.
RUN composer dump-autoload --optimize --no-dev \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

# Render provides $PORT; make Apache listen on it.
RUN sed -ri -e 's!Listen 80!Listen ${PORT}!' /etc/apache2/ports.conf \
    && sed -ri -e 's!:80>!:${PORT}>!' /etc/apache2/sites-available/*.conf

# Entrypoint runs migrations/caches at boot, then starts Apache.
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE ${PORT}
CMD ["/usr/local/bin/entrypoint.sh"]
