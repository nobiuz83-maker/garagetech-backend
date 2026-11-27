#!/bin/sh
set -eu
if [ -f /var/www/html/.env ]; then
    echo 'Refusing a host .env inside the container; supply explicit container configuration.' >&2
    exit 78
fi
if [ -n "${APP_KEY_FILE:-}" ]; then
    APP_KEY=$(cat "$APP_KEY_FILE")
    export APP_KEY
fi
if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    DB_PASSWORD=$(cat "$DB_PASSWORD_FILE")
    export DB_PASSWORD
fi
if [ -z "${APP_KEY:-}" ] || [ -z "${DB_PASSWORD:-}" ]; then
    echo 'An application key and existing database credentials are required.' >&2
    exit 78
fi
if [ ! -f vendor/autoload.php ]; then
    echo 'Composer dependencies are missing; build the selected image first.' >&2
    exit 78
fi
mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
php artisan package:discover --no-interaction >/dev/null
# Schema and account management remain explicit deployment operations.
exec "$@"
