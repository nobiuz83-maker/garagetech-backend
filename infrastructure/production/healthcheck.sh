#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
environment_file="$production_root/.env.production"
compose_file="$deployment_directory/compose.yaml"
compose=(docker compose --env-file "$environment_file" -f "$compose_file")

"${compose[@]}" ps
for service_name in gateway frontend auth-service clients-service appointments-service \
  interventions-service inventory-service billing-service accounting-service \
  appointments-notification-worker appointments-scheduler; do
  health_status="$("${compose[@]}" ps --format json "$service_name" | \
    python3 -c 'import json,sys; rows=[json.loads(line) for line in sys.stdin if line.strip()]; print(rows[0].get("Health", "") if rows else "")')"
  if [[ "$health_status" != healthy ]]; then
    echo "Service $service_name is not healthy." >&2
    exit 1
  fi
done

for database_service in database-auth database-clients database-appointments \
  database-interventions database-inventory database-billing database-accounting; do
  if [[ -n $("${compose[@]}" port "$database_service" 3306 2>/dev/null || true) ]]; then
    echo "Database $database_service unexpectedly publishes port 3306." >&2
    exit 1
  fi
done

public_domain="$(sed -n 's/^PUBLIC_DOMAIN=//p' "$environment_file" | tail -n 1)"
if [[ -z "$public_domain" || "$public_domain" == replace-* ]]; then
  echo "Set PUBLIC_DOMAIN before running public health checks." >&2
  exit 1
fi
curl --fail --silent --show-error --retry 5 --retry-delay 2 \
  "https://${public_domain}/gateway-health" >/dev/null
printf 'All application health checks passed for https://%s.\n' "$public_domain"
