#!/usr/bin/env bash
set -euo pipefail

backend_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
compose_file="$backend_directory/.private/office/compose.json"
action="${1:-}"

if [[ ! -f "$compose_file" ]]; then
  echo "Persistent OFFICE configuration is missing: $compose_file" >&2
  exit 1
fi

case "$action" in
  start) docker compose -f "$compose_file" up -d --wait --no-recreate ;;
  stop) docker compose -f "$compose_file" stop ;;
  status) docker compose -f "$compose_file" ps ;;
  backup) python3 "$backend_directory/infrastructure/profiles/backup-office.py" ;;
  verify) python3 "$backend_directory/infrastructure/profiles/smoke-office.py" --verify ;;
  *) echo "Usage: $0 {start|stop|status|backup|verify}" >&2; exit 2 ;;
esac
