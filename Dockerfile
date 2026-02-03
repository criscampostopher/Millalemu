FROM php:8.2-apache

# Extensiones necesarias para PostgreSQL (PDO)
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite

# Copiar proyecto al docroot de Apache
COPY . /var/www/html

# Script para que Apache escuche el puerto de Railway
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

# Railway normalmente usa 8080, pero en runtime entrega $PORT
EXPOSE 8080

CMD ["/start.sh"]
