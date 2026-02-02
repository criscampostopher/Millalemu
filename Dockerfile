FROM php:8.2

RUN apt-get update && apt-get install -y \
    libpq-dev \
    apache2 \
    && docker-php-ext-install pdo_pgsql pgsql

# Copiar proyecto
COPY . /var/www/html

# Apache config mínima
RUN echo "<VirtualHost *:8080>
    DocumentRoot /var/www/html
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>" > /etc/apache2/sites-available/000-default.conf

RUN a2enmod rewrite

EXPOSE 8080

CMD ["apachectl", "-D", "FOREGROUND"]
