#!/usr/bin/env bash
set -euo pipefail
umask 077

mysql_defaults_file="${MYSQL_DEFAULTS_FILE:-/etc/vicartech/mariadb-backup.cnf}"
backup_directory="${BACKUP_DIRECTORY:-/var/backups/vicartech}"
age_recipient_file="${AGE_RECIPIENT_FILE:-/etc/vicartech/backup-age-recipient.txt}"
private_storage_root="${PRIVATE_STORAGE_ROOT:-/srv/vicartech/shared/private-storage}"

for required_file in "$mysql_defaults_file" "$age_recipient_file"; do
  [[ -r "$required_file" ]] || { echo "Required backup configuration is unavailable." >&2; exit 1; }
done
command -v mariadb-dump >/dev/null
command -v age >/dev/null
install -d -m 0700 "$backup_directory"
temporary_directory="$(mktemp -d "$backup_directory/.snapshot.XXXXXX")"
trap 'rm -rf "$temporary_directory"' EXIT
payload_directory="$temporary_directory/payload"
install -d -m 0700 "$payload_directory"

service_names=(auth clients appointments interventions inventory billing accounting)
for service_name in "${service_names[@]}"; do
  database_name="vicartech_${service_name}"
  mariadb-dump --defaults-extra-file="$mysql_defaults_file" \
    --single-transaction --routines --events --triggers "$database_name" \
    > "$payload_directory/${database_name}.sql"
done

for service_name in appointments billing; do
  private_storage="$private_storage_root/$service_name"
  [[ -d "$private_storage" ]] || { echo "Expected private document storage is missing." >&2; exit 1; }
  tar -czf "$payload_directory/${service_name}-private-storage.tar.gz" \
    -C "$private_storage" .
done

archive_file="$temporary_directory/vicartech-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
tar -czf "$archive_file" -C "$payload_directory" .
encrypted_file="$backup_directory/$(basename "$archive_file").age"
age --encrypt --recipients-file "$age_recipient_file" \
  --output "$encrypted_file" "$archive_file"
chmod 0600 "$encrypted_file"
printf 'Encrypted local snapshot created: %s\n' "$encrypted_file"
echo "Copy it to an independently protected off-host destination and verify a restore rehearsal."
