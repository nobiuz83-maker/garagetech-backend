#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
compose=(docker compose --env-file "$production_root/.env.production" -f "$deployment_directory/compose.yaml")

"${compose[@]}" run --rm certbot renew --webroot --webroot-path /var/www/certbot --quiet
"${compose[@]}" exec -T gateway nginx -s reload
