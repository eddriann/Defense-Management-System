#!/bin/sh
set -e

PORT="${PORT:-10000}"

# Make Apache listen on Render's port
sed -i "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Silence the ServerName warning
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null

# Make sure uploads can be written (the folder is recreated empty on each deploy)
mkdir -p /var/www/html/storage/manuscripts
chown -R www-data:www-data /var/www/html/storage

exec apache2-foreground