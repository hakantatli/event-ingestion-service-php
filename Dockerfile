# Use official FrankenPHP image with PHP 8.5
FROM dunglas/frankenphp:php8.5-alpine

# Set working directory
WORKDIR /app

# Install dependencies required for Laravel and Redis
RUN apk add --no-cache \
    git \
    unzip \
    libzip-dev \
    icu-dev \
    bash \
    $PHPIZE_DEPS \
    linux-headers \
    && docker-php-ext-install \
    pcntl \
    intl \
    zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS linux-headers \
    && echo -e "opcache.enable_cli=1\nopcache.validate_timestamps=0" >> /usr/local/etc/php/conf.d/docker-php-ext-opcache.ini

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application files
COPY . .

# Install PHP dependencies
RUN composer install --ignore-platform-reqs --no-dev --optimize-autoloader -q

# Expose Octane port
EXPOSE 8000

# Command to start Octane
CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000"]
