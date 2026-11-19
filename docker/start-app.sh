#!/bin/sh
set -eu

: "${APP_KEY:?Set APP_KEY in .env before starting GigKampus}"

install -d -o www-data -g www-data -m 755 \
    bootstrap/cache storage/app/public storage/framework/cache/data \
    storage/framework/cache/locks storage/framework/sessions \
    storage/framework/views storage/logs
install -d -o www-data -g www-data -m 750 storage/app/private

runuser -u www-data -- php artisan migrate --force
exec php-fpm
