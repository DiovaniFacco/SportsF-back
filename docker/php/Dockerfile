FROM php:8.4-fpm

# Install system dependencies & PHP extensions helper
ADD https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

RUN chmod +x /usr/local/bin/install-php-extensions && \
    install-php-extensions pdo_pgsql pgsql pdo_sqlite sqlite3 zip gd opcache bcmath intl dom xml mbstring

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www

# Copy custom php.ini configuration
COPY docker/php/local.ini /usr/local/etc/php/conf.d/local.ini

# Entrypoint ajusta dono de storage/ e bootstrap/cache para www-data
# (o volume montado pertence ao uid do host e impedia escrita de logs).
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
