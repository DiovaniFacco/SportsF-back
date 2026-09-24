#!/bin/sh
# Entrypoint do container PHP (back-app).
# Garante que o php-fpm (usuário www-data) consiga escrever em storage/
# (logs, cache, sessões, views compiladas) e bootstrap/cache, que no volume
# montado costumam pertencer ao uid do host. Sem isso, qualquer exceção
# sequer é registrada e a resposta de erro vaza falha de log (B1).
set -e

chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache 2>/dev/null || true

exec "$@"
