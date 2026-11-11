FROM php:8.4-cli-alpine

# Install system dependencies and PHP extensions
RUN apk add --no-cache \
    postgresql-dev \
    libzip-dev \
    zip \
    unzip \
    git \
    linux-headers \
    && docker-php-ext-install pdo pdo_pgsql sockets pcntl bcmath

WORKDIR /var/www

COPY . .

# Set permissions for Laravel storage and bootstrap cache
RUN chmod -R 777 storage bootstrap/cache

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
