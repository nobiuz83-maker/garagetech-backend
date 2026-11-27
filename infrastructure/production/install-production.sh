#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
secrets_directory="${PRODUCTION_SECRETS_DIR:-$production_root/shared/secrets}"

if [[ "$(id -u)" -eq 0 ]]; then
  echo "Run this as the dedicated deployment user, not root." >&2
  exit 1
fi
if [[ ! -f "$deployment_directory/compose.yaml" ]]; then
  echo "Production Compose files are missing from $deployment_directory." >&2
  exit 1
fi

install -d -m 0700 "$production_root/shared" "$production_root/backups"
install -d -m 0750 "$production_root/deployment"
if [[ ! -f "$production_root/.env.production" ]]; then
  cp "$deployment_directory/.env.example" "$production_root/.env.production"
  chmod 0600 "$production_root/.env.production"
  echo "Created $production_root/.env.production; edit it before continuing."
fi

if [[ ! -d "$secrets_directory" ]]; then
  PRODUCTION_ROOT="$production_root" "$deployment_directory/provision-secrets.sh"
fi

cat <<EOF
Production files are installed without starting containers.

Next steps:
1. Edit $production_root/.env.production and set the domain, GHCR owner, image tags, and offsite backup remote.
2. Add the Gmail application password to $production_root/shared/notification-provider.env.
3. Configure an existing rclone offsite destination and verify its credentials.
4. Configure a read-only GHCR pull login for this deployment user.
5. Point the domain's A record to this server and allow TCP 80/443.
6. Obtain the first Let's Encrypt certificate, then run deploy.sh.
EOF
