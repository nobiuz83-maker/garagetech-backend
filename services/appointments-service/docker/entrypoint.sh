#!/bin/sh
set -eu
if [ -f /var/www/html/.env ]; then
    echo 'Use external runtime secrets, not a copied .env file.' >&2
    exit 78
fi
APP_KEY=$(cat "$APP_KEY_FILE")
DB_PASSWORD=$(cat "$DB_PASSWORD_FILE")
export APP_KEY DB_PASSWORD
exec "$@"
