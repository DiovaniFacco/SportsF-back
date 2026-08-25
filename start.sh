#!/bin/bash
set -e

PORT="${PORT:-8080}"

# Update Apache listening port dynamically for Render
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Cache configuration, routes, and views for optimal production performance
echo "Caching Laravel configuration and routes..."
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache

# Ensure storage link is created
php artisan storage:link --force || true

# Run database migrations
echo "Running database migrations..."
php artisan migrate --force

echo "Starting Apache on port ${PORT}..."
exec apache2-foreground
