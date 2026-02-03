FROM php:8.2-apache

# Dependencias necesarias para compilar pdo_pgsql (PostgreSQL)
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql \
    && apt-get purge -y --auto-remove libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY . /var/www/html/
WORKDIR /var/www/html/

EXPOSE 80
