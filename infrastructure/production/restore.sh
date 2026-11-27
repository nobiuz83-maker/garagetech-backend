#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
environment_file="$production_root/.env.production"
compose_file="$deployment_directory/compose.yaml"
backup_file="${1:-}"
identity_file="$production_root/shared/backup-age-identity.txt"

if [[ "${1:-}" == "--confirm-empty-target" ]]; then
  echo "Pass the encrypted archive path first and --confirm-empty-target second." >&2
  exit 2
fi
if [[ "${2:-}" != "--confirm-empty-target" ]]; then
  echo "Restore requires explicit confirmation. Re-run with: $0 '$backup_file' --confirm-empty-target" >&2
  exit 3
fi
if [[ ! -f "$backup_file" || ! -r "$identity_file" ]]; then
  echo "Pass a readable encrypted backup and ensure the private age identity is present." >&2
  exit 1
fi
compose() {
  docker compose --env-file "$environment_file" -f "$compose_file" "$@"
}

temporary_directory="$(mktemp -d "$production_root/.restore.XXXXXX")"
trap 'rm -rf "$temporary_directory"' EXIT
age --decrypt --identity "$identity_file" --output "$temporary_directory/backup.tar.gz" "$backup_file"
tar -tzf "$temporary_directory/backup.tar.gz" > "$temporary_directory/archive-files.txt"
for expected_dump in vicartech_auth.sql vicartech_clients.sql vicartech_appointments.sql \
  vicartech_interventions.sql vicartech_inventory.sql vicartech_billing.sql vicartech_accounting.sql; do
  if ! grep -qx "$expected_dump" "$temporary_directory/archive-files.txt"; then
    echo "The encrypted archive is missing a database dump; restore cancelled." >&2
    exit 1
  fi
done

for volume_name in vicartech_production_appointments_documents vicartech_production_billing_logos; do
  volume_has_files="$(docker run --rm --network none --mount "type=volume,src=${volume_name},dst=/source,readonly" alpine:3.21 sh -c 'find /source -mindepth 1 -print -quit')"
  if [[ -n "$volume_has_files" ]]; then
    echo "Persistent volume ${volume_name} is not empty; restore files to a new volume instead." >&2
    exit 1
  fi
done
tar -xzf "$temporary_directory/backup.tar.gz" -C "$temporary_directory"

application_services=(auth-service clients-service appointments-service interventions-service inventory-service billing-service accounting-service appointments-notification-worker appointments-scheduler)
for application_service in "${application_services[@]}"; do
  if [[ -n "$(compose ps -q "$application_service")" ]]; then
    echo "Application service $application_service is running; stop the full application stack before restore." >&2
    exit 1
  fi
done

compose up -d --wait database-auth database-clients database-appointments \
  database-interventions database-inventory database-billing database-accounting

database_services=(auth clients appointments interventions inventory billing accounting)
for database_service in "${database_services[@]}"; do
  container_status="$(compose ps --format json "database-${database_service}" | python3 -c 'import json,sys; rows=[json.loads(line) for line in sys.stdin if line.strip()]; print(rows[0].get("State", "") if rows else "")')"
  if [[ "$container_status" != *running* ]]; then
    echo "Database ${database_service} is not running; restore cancelled." >&2
    exit 1
  fi
  empty_table_count="$(compose exec -T "database-${database_service}" sh -eu -c '
    credential_file="$(mktemp)"
    trap '\''rm -f "$credential_file"'\'' EXIT
    printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/mariadb_root_password)" > "$credential_file"
    chmod 0600 "$credential_file"
    mariadb --defaults-extra-file="$credential_file" --batch --skip-column-names -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '\''$1'\''"
  ' sh "vicartech_${database_service}")"
  if [[ "$empty_table_count" != 0 ]]; then
    echo "Database ${database_service} is not empty; restore cancelled without changing it." >&2
    exit 1
  fi
done

for database_service in "${database_services[@]}"; do
  database_name="vicartech_${database_service}"
  compose exec -T "database-${database_service}" sh -eu -c '
    credential_file="$(mktemp)"
    trap '\''rm -f "$credential_file"'\'' EXIT
    printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/mariadb_root_password)" > "$credential_file"
    chmod 0600 "$credential_file"
    exec mariadb --defaults-extra-file="$credential_file"
  ' < "$temporary_directory/${database_name}.sql"
done

for volume_name in vicartech_production_appointments_documents vicartech_production_billing_logos; do
  archive_member="$temporary_directory/${volume_name}.tar.gz"
  [[ -f "$archive_member" ]] || continue
  docker run --rm --network none --mount "type=volume,src=${volume_name},dst=/target" \
    -i alpine:3.21 sh -c 'tar -xzf - -C /target' < "$archive_member"
done

echo "Restore completed into empty databases and document volumes only. No existing database was cleared."
