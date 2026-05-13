# PAManager — Dockerfile multi-stage per Dokploy / Docker Compose
# Base: PHP 8.3 + Apache

FROM php:8.3-apache AS base

# --- System dependencies + PHP extensions ---
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev unzip \
        libicu-dev libonig-dev \
        libcurl4-openssl-dev libssl-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev libwebp-dev \
        libgmp-dev \
        default-mysql-client \
        ca-certificates \
        cron \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql mysqli zip intl mbstring gd gmp bcmath opcache \
    && a2enmod rewrite headers expires deflate

# --- Composer ---
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- Apache document root → /var/www/html/public ---
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-pamanager.ini

# --- App code ---
WORKDIR /var/www/html
COPY . /var/www/html/

# Install Composer dependencies (web-push e altre)
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts \
    && composer clear-cache

# --- Permissions + ensure writable dirs exist ---
RUN mkdir -p \
        /var/www/html/public/uploads \
        /var/www/html/logs \
        /var/www/html/storage/logs \
    && chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \; \
    && chmod -R 775 /var/www/html/public/uploads /var/www/html/logs /var/www/html/storage

# --- Entrypoint ---
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=3 \
    CMD curl -fsS http://localhost/ -o /dev/null || exit 1

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
