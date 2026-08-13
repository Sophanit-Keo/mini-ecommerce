#!/usr/bin/env sh
set -eu

: "${PORT:=10000}"

mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# Migrations are opt-in to prevent an application deploy from unexpectedly changing
# a shared production database. Run them through Render's one-off shell or set this
# to true only for a controlled deployment.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

php artisan config:cache
php artisan route:cache

sed -ri "s/Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
