#!/usr/bin/env bash
set -euo pipefail

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
deployment_directory="${DEPLOYMENT_DIRECTORY:-$production_root/deployment/infrastructure/production}"
environment_file="$production_root/.env.production"
compose_file="$deployment_directory/compose.yaml"

read_environment_value() {
  local variable_name="$1"
  sed -n "s/^${variable_name}=//p" "$environment_file" | tail -n 1
}

backup_remote="${OFFSITE_BACKUP_REMOTE:-$(read_environment_value OFFSITE_BACKUP_REMOTE)}"
recipient_file="${AGE_RECIPIENT_FILE:-$(read_environment_value AGE_RECIPIENT_FILE)}"
rclone_configuration="${RCLONE_CONFIG:-$(read_environment_value RCLONE_CONFIG)}"
retention_days="${BACKUP_RETENTION_DAYS:-$(read_environment_value BACKUP_RETENTION_DAYS)}"
recipient_file="${recipient_file:-$production_root/shared/backup-age-recipient.txt}"
rclone_configuration="${rclone_configuration:-$production_root/shared/rclone.conf}"
retention_days="${retention_days:-30}"

if [[ -z "$backup_remote" || "$backup_remote" == *replace-with-existing* ]]; then
  echo "Set OFFSITE_BACKUP_REMOTE to an already configured offsite rclone destination." >&2
  exit 1
fi
for required_file in "$environment_file" "$recipient_file" "$rclone_configuration"; do
  if [[ ! -r "$required_file" ]]; then
    echo "Required private backup configuration is not readable." >&2
    exit 1
  fi
done

compose() {
  docker compose --env-file "$environment_file" -f "$compose_file" "$@"
}

backup_directory="$production_root/backups"
install -d -m 0700 "$backup_directory"
timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
temporary_directory="$(mktemp -d "$production_root/.backup.XXXXXX")"
trap 'rm -rf "$temporary_directory"' EXIT

database_services=(auth clients appointments interventions inventory billing accounting)
for database_service in "${database_services[@]}"; do
  database_name="vicartech_${database_service}"
  compose exec -T "database-${database_service}" sh -eu -c '
    credential_file="$(mktemp)"
    trap '\''rm -f "$credential_file"'\'' EXIT
    printf "[client]\nuser=root\npassword=%s\n" "$(cat /run/secrets/mariadb_root_password)" > "$credential_file"
    chmod 0600 "$credential_file"
    exec mariadb-dump --defaults-extra-file="$credential_file" --single-transaction --routines --events --triggers --databases "$1"
  ' sh "$database_name" > "$temporary_directory/${database_name}.sql"
done

for volume_name in vicartech_production_appointments_documents vicartech_production_billing_logos; do
  docker run --rm --network none \
    --mount "type=volume,src=${volume_name},dst=/source,readonly" \
    alpine:3.21 tar -czf - -C /source . > "$temporary_directory/${volume_name}.tar.gz"
done

archive_path="$temporary_directory/vicartech-production-${timestamp}.tar.gz"
tar -czf "$archive_path" -C "$temporary_directory" \
  vicartech_auth.sql vicartech_clients.sql vicartech_appointments.sql \
  vicartech_interventions.sql vicartech_inventory.sql vicartech_billing.sql \
  vicartech_accounting.sql vicartech_production_appointments_documents.tar.gz \
  vicartech_production_billing_logos.tar.gz

encrypted_backup="$backup_directory/vicartech-production-${timestamp}.tar.gz.age"
age --encrypt --recipients-file "$recipient_file" --output "$encrypted_backup" "$archive_path"
chmod 0600 "$encrypted_backup"
rclone copyto "$encrypted_backup" "${backup_remote%/}/$(basename "$encrypted_backup")" \
  --config "$rclone_configuration"

find "$backup_directory" -type f -name 'vicartech-production-*.age' \
  -mtime "+$retention_days" -delete
printf 'Encrypted backup uploaded off-server: %s\n' "$(basename "$encrypted_backup")"
