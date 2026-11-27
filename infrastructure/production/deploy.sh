#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
environment_file="$production_root/.env.production"
compose_file="$deployment_directory/compose.yaml"
backend_image_tag="${1:-}"
frontend_image_tag="${2:-}"

if [[ ! -f "$environment_file" || ! -f "$compose_file" ]]; then
  echo "Production environment or Compose configuration is missing." >&2
  exit 1
fi
if [[ ! "$backend_image_tag" =~ ^[A-Za-z0-9._-]+$ || ! "$frontend_image_tag" =~ ^[A-Za-z0-9._-]+$ ]]; then
  echo "Pass immutable backend and frontend image tags." >&2
  exit 1
fi
if [[ ! -f "$production_root/shared/notification-provider.env" ]]; then
  echo "The private mail provider configuration is missing." >&2
  exit 1
fi

compose() {
  docker compose --env-file "$environment_file" -f "$compose_file" "$@"
}

if [[ -f "$production_root/.current-release" ]]; then
  cp "$production_root/.current-release" "$production_root/.previous-release"
fi

temporary_environment="$(mktemp "$production_root/.env.production.XXXXXX")"
trap 'rm -f "$temporary_environment"' EXIT
awk -v backend_tag="$backend_image_tag" -v frontend_tag="$frontend_image_tag" '
  /^BACKEND_IMAGE_TAG=/ { print "BACKEND_IMAGE_TAG=" backend_tag; next }
  /^FRONTEND_IMAGE_TAG=/ { print "FRONTEND_IMAGE_TAG=" frontend_tag; next }
  { print }
' "$environment_file" > "$temporary_environment"
chmod 0600 "$temporary_environment"
mv "$temporary_environment" "$environment_file"
trap - EXIT

compose pull
compose up -d --wait \
  database-auth database-clients database-appointments database-interventions \
  database-inventory database-billing database-accounting

# This archive is encrypted and uploaded off-server before any schema change.
"$deployment_directory/backup.sh"

services=(auth-service clients-service appointments-service interventions-service inventory-service billing-service accounting-service)
for service_name in "${services[@]}"; do
  compose run --rm --no-deps "$service_name" php artisan migrate --force --no-interaction
done

compose up -d --wait
"$deployment_directory/healthcheck.sh"
printf 'BACKEND_IMAGE_TAG=%s\nFRONTEND_IMAGE_TAG=%s\n' \
  "$backend_image_tag" "$frontend_image_tag" > "$production_root/.current-release"
chmod 0600 "$production_root/.current-release"
printf 'Deployment healthy: backend=%s frontend=%s\n' "$backend_image_tag" "$frontend_image_tag"
