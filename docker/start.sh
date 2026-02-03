#!/bin/bash
set -e

PORT_TO_USE="${PORT:-8080}"

# Apache debe escuchar el puerto de Railway
# Cambia cualquier "Listen 80" o "Listen xxxx" por el puerto real
sed -i "s/^Listen .*/Listen ${PORT_TO_USE}/" /etc/apache2/ports.conf

# Cambia el VirtualHost a ese puerto (si existe)
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT_TO_USE}>/g" /etc/apache2/sites-available/000-default.conf
sed -i "s/<VirtualHost \*:[0-9]\+>/<VirtualHost *:${PORT_TO_USE}>/g" /etc/apache2/sites-available/000-default.conf

# ServerName para evitar warnings
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null

# Levantar Apache
apache2-foreground
