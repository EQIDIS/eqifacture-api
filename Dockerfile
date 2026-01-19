FROM php:8.3-fpm-alpine

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libxml2-dev \
    zip \
    unzip \
    postgresql-dev \
    libzip-dev \
    oniguruma-dev \
    linux-headers \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    sockets

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Create storage directories for Swagger docs
RUN mkdir -p /var/www/html/storage/api-docs

# Set permissions
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Generate Swagger docs and cache configs (uses default .env or build args)
# Note: These will be re-cached at runtime with proper env if needed
RUN php artisan l5-swagger:generate || true

# Expose port
EXPOSE 8000

# Start command with optimizations
CMD php artisan config:cache && php artisan route:cache && php artisan serve --host=0.0.0.0 --port=8000
