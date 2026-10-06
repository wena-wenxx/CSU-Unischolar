# CSU UniScholar backend (Laravel 12, PHP 8.3) - production container.
# Used by Railway (or Render) when deploying the "backend" folder.
FROM php:8.3-cli

# System libraries + PHP extensions Laravel and MySQL need
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Allow document uploads up to 10 MB (Laravel validates max:10240)
RUN { \
      echo "upload_max_filesize=12M"; \
      echo "post_max_size=16M"; \
      echo "memory_limit=256M"; \
    } > /usr/local/etc/php/conf.d/uploads.ini

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP packages first (better build caching)
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-scripts --prefer-dist --optimize-autoloader

COPY . .
RUN composer dump-autoload --optimize --no-dev \
    && chmod +x docker/start.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PHP_CLI_SERVER_WORKERS=4

EXPOSE 8080
CMD ["docker/start.sh"]
