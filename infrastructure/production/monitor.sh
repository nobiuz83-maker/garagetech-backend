#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
compose=(docker compose --env-file "$production_root/.env.production" -f "$deployment_directory/compose.yaml")
"${compose[@]}" ps
docker stats --no-stream --format '{{.Name}} CPU={{.CPUPerc}} RAM={{.MemUsage}}' \
  $("${compose[@]}" ps -q)
