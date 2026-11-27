#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
environment_file="$production_root/.env.production"
compose_file="$deployment_directory/compose.yaml"
public_domain="$(sed -n 's/^PUBLIC_DOMAIN=//p' "$environment_file" | tail -n 1)"
acme_email="$(sed -n 's/^PUBLIC_ACME_EMAIL=//p' "$environment_file" | tail -n 1)"

if [[ -z "$public_domain" || "$public_domain" == replace-* || -z "$acme_email" || "$acme_email" == replace-* ]]; then
  echo "Set the public domain and ACME email in .env.production first." >&2
  exit 1
fi
if [[ -n "$(ss -lnt '( sport = :80 )' | tail -n +2)" ]]; then
  echo "TCP port 80 is already in use; stop the conflicting service before certificate bootstrap." >&2
  exit 1
fi

docker compose --profile certificate-bootstrap --env-file "$environment_file" \
  -f "$compose_file" run --rm -p 80:80 certbot certonly --standalone \
  --non-interactive --agree-tos --email "$acme_email" -d "$public_domain"
printf 'Initial certificate issued for %s. The public gateway can now start.\n' "$public_domain"
