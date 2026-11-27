#!/usr/bin/env bash
set -euo pipefail

: "${BACKUP_DIRECTORY:?Set a directory on a separate mounted backup disk}"
: "${BACKUP_PASSWORD_FILE:?Set the private MariaDB backup password file}"
: "${BACKUP_DATABASE_HOST:?Set the private database host}"
: "${BACKUP_DATABASE_USER:?Set a read-only backup user}"

umask 077
if [[ "${BACKUP_REQUIRE_MOUNT:-true}" == "true" ]] && ! mountpoint -q "$BACKUP_DIRECTORY"; then
  echo "Backup directory must be a mounted volume separate from the VM." >&2
  exit 1
fi
if [[ ! -f "$BACKUP_PASSWORD_FILE" || -L "$BACKUP_PASSWORD_FILE" ]]; then
  echo "Backup password must be a regular file, not a symbolic link." >&2
  exit 1
fi
if [[ "$(stat -c %a "$BACKUP_PASSWORD_FILE")" != "600" ]]; then
  echo "Backup password file must have mode 0600." >&2
  exit 1
fi
mkdir -p "$BACKUP_DIRECTORY"
chmod 700 "$BACKUP_DIRECTORY"
backup_timestamp="$(date -u +%Y%m%dT%H%M%SZ)"
backup_file="$BACKUP_DIRECTORY/vicartech-$backup_timestamp.sql.gz"
temporary_file="$backup_file.partial"
trap 'rm -f "$temporary_file"' EXIT

MYSQL_PWD="$(cat "$BACKUP_PASSWORD_FILE")" mariadb-dump \
  --host="$BACKUP_DATABASE_HOST" --port="${BACKUP_DATABASE_PORT:-3306}" --user="$BACKUP_DATABASE_USER" \
  --single-transaction --quick --routines --triggers --events \
  --databases auth_db vicartech_clients vicartech_appointments \
    vicartech_interventions vicartech_inventory vicartech_billing vicartech_accounting \
  | gzip -9 > "$temporary_file"

test -s "$temporary_file"
gzip -t "$temporary_file"
mv "$temporary_file" "$backup_file"
trap - EXIT
find "$BACKUP_DIRECTORY" -maxdepth 1 -type f -name 'vicartech-*.sql.gz' -mtime +30 -delete
