#!/usr/bin/env bash
set -e

# Render injects $PORT (default 10000 or 80)
PORT="${PORT:-80}"

# Adjust Apache listening port dynamically
if [ -f /etc/apache2/ports.conf ]; then
    sed -i "s/Listen [0-9]*/Listen ${PORT}/" /etc/apache2/ports.conf
fi

if [ -f /etc/apache2/sites-available/000-default.conf ]; then
    sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost \*:${PORT}>/" /etc/apache2/sites-available/000-default.conf
fi

# Ensure storage directories exist and are writable by Apache user
mkdir -p /var/www/html/storage/rate-limit
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

exec "$@"
