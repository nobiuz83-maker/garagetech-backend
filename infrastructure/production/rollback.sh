#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
environment_file="$production_root/.env.production"
compose_file="$deployment_directory/compose.yaml"
previous_release="$production_root/.previous-release"

if [[ ! -r "$previous_release" ]]; then
  echo "No previous successful image release is recorded." >&2
  exit 1
fi
backend_image_tag="$(sed -n 's/^BACKEND_IMAGE_TAG=//p' "$previous_release")"
frontend_image_tag="$(sed -n 's/^FRONTEND_IMAGE_TAG=//p' "$previous_release")"
if [[ -z "$backend_image_tag" || -z "$frontend_image_tag" ]]; then
  echo "Previous image tags are incomplete; rollback cancelled." >&2
  exit 1
fi

temporary_environment="$(mktemp "$production_root/.rollback.XXXXXX")"
trap 'rm -f "$temporary_environment"' EXIT
awk -v backend_tag="$backend_image_tag" -v frontend_tag="$frontend_image_tag" '
  /^BACKEND_IMAGE_TAG=/ { print "BACKEND_IMAGE_TAG=" backend_tag; next }
  /^FRONTEND_IMAGE_TAG=/ { print "FRONTEND_IMAGE_TAG=" frontend_tag; next }
  { print }
' "$environment_file" > "$temporary_environment"
chmod 0600 "$temporary_environment"
mv "$temporary_environment" "$environment_file"
trap - EXIT

compose=(docker compose --env-file "$environment_file" -f "$compose_file")
"${compose[@]}" pull auth-service clients-service appointments-service interventions-service \
  inventory-service billing-service accounting-service frontend gateway \
  appointments-notification-worker appointments-scheduler
"${compose[@]}" up -d --wait --no-deps auth-service clients-service appointments-service \
  interventions-service inventory-service billing-service accounting-service frontend \
  gateway appointments-notification-worker appointments-scheduler
"$deployment_directory/healthcheck.sh"
printf 'Application images rolled back to backend=%s frontend=%s. Database schema was not rolled back.\n' \
  "$backend_image_tag" "$frontend_image_tag"
