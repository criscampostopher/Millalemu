FROM php:8.2-apache

# PostgreSQL + PDO
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && a2enmod rewrite \
    \
    # 🔥 FIX MPM: dejar SOLO prefork
    && a2dismod mpm_event mpm_worker || true \
    && a2enmod mpm_prefork \
    \
    && rm -rf /var/lib/apt/lists/*

# Copiar proyecto
COPY . /var/www/html

# Script de arranque
COPY docker/start.sh /start.sh
RUN chmod +x /start.sh

EXPOSE 8080
CMD ["/start.sh"]

