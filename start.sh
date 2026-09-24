#!/bin/bash
set -e

PORT="${PORT:-8080}"

# Disable conflicting MPM modules to prevent AH00534 error on Railway/Render
a2dismod mpm_event mpm_worker 2>/dev/null || true
a2enmod mpm_prefork 2>/dev/null || true

# Update Apache listening port dynamically for Render / Railway
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

# Run database migrations only (no auto-seed).
# O seed automático foi removido de propósito: ele recriava as quadras/
# complexos genéricos por cima dos dados reais a cada deploy/restart.
# Rode manualmente apenas se precisar dos dados essenciais
# (admin, esportes, amenities): php artisan db:seed --force
echo "Running database migrations..."
php artisan migrate --force

echo "Starting Apache on port ${PORT}..."
exec apache2-foreground
