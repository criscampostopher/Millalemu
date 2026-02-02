FROM php:8.2-apache

# Instalar dependencias y extensiones PostgreSQL
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite

# Copiar el proyecto al docroot de Apache
COPY . /var/www/html

# Script para usar el PORT de Railway
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080

CMD ["/start.sh"]
