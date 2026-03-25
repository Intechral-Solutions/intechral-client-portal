#!/bin/bash
set -e

cd /var/www/app

# Copy .env if it doesn't exist
if [ ! -f .env ]; then
    echo "Creating .env from .env.example..."
    cp .env.example .env
fi

# Generate app key if not set
if grep -q "APP_KEY=$" .env || grep -q "APP_KEY=base64:$" .env; then
    echo "Generating application key..."
    php artisan key:generate --no-interaction
fi

# Wait for database to be ready
echo "Waiting for database connection..."
until php artisan db:show > /dev/null 2>&1; do
    echo "Database not ready — retrying in 2s..."
    sleep 2
done

echo "Database ready."

# Run migrations
php artisan migrate --no-interaction --force

# Clear and cache config in production
if [ "$APP_ENV" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan view:cache
fi

# Start PHP-FPM
exec php-fpm
