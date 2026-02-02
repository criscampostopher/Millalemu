FROM php:8.2-apache

# Instalar dependencias de PostgreSQL
RUN apt-get update \
    && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Habilitar mod_rewrite (para URLs limpias si usas .htaccess)
RUN a2enmod rewrite

# Copiar el proyecto al directorio público de Apache
COPY . /var/www/html/

# Permisos correctos (evita 502 por permisos)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Puerto estándar
EXPOSE 80
