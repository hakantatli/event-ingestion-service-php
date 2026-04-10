# Use official FrankenPHP image with PHP 8.3
FROM dunglas/frankenphp:php8.3-alpine

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
    && apk del $PHPIZE_DEPS linux-headers

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
