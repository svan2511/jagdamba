# Stage 1: Composer (ignore platform reqs for your lock file issue)
FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock* ./
RUN composer install --no-dev --optimize-autoloader --prefer-dist --no-scripts --no-interaction --ignore-platform-reqs

# Stage 2: Final image - PHP 8.3 FPM + Nginx + Supervisor
FROM php:8.3-fpm-alpine

# Install packages + GD deps + netcat for entrypoint DB wait
RUN apk update && apk add --no-cache \
    nginx \
    supervisor \
    libzip-dev \
    zip \
    unzip \
    git \
    curl \
    freetype-dev \
    libjpeg-turbo-dev \
    libpng-dev \
    netcat-openbsd && \
  docker-php-ext-configure gd --with-freetype --with-jpeg && \
  docker-php-ext-install -j$(nproc) \
    pdo_mysql \
    zip \
    pcntl \
    bcmath \
    gd \
    exif && \
  apk add --no-cache --virtual .build-deps $PHPIZE_DEPS && \
  pecl install redis && \
  docker-php-ext-enable redis && \
  apk del .build-deps

# Copy configs (same)
COPY nginx.conf /etc/nginx/http.d/default.conf
COPY supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# App setup
WORKDIR /var/www/html
COPY . /var/www/html
COPY --from=composer /app/vendor /var/www/html/vendor

# Permissions fix: Create missing directories first, then chown & chmod
RUN mkdir -p /var/www/html/bootstrap/cache \
    && mkdir -p /var/www/html/storage/framework/{cache,sessions,views} \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Laravel cache optimizations (now safe after COPY and mkdir)
RUN php artisan config:cache \
    && php artisan route:cache \
    && php artisan view:cache

# Copy entrypoint script and make it executable
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# Use entrypoint to run migrations/passport at startup, then start supervisor
ENTRYPOINT ["/entrypoint.sh"]

EXPOSE 80