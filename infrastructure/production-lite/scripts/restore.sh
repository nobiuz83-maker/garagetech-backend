#!/usr/bin/env bash
set -euo pipefail
umask 077

encrypted_backup="${1:-}"
confirmation="${2:-}"
mysql_defaults_file="${MYSQL_DEFAULTS_FILE:-/etc/vicartech/mariadb-backup.cnf}"
age_identity_file="${AGE_IDENTITY_FILE:-/etc/vicartech/backup-age-identity.txt}"
private_storage_root="${PRIVATE_STORAGE_ROOT:-/srv/vicartech/shared/private-storage}"
[[ "$confirmation" == "--confirm-empty-target" ]] || {
  echo "Restore requires: $0 BACKUP.tar.gz.age --confirm-empty-target" >&2
  exit 2
}
[[ -r "$encrypted_backup" && -r "$mysql_defaults_file" && -r "$age_identity_file" ]] || {
  echo "Encrypted backup or private restore configuration is unavailable." >&2
  exit 1
}

temporary_directory="$(mktemp -d)"
trap 'rm -rf "$temporary_directory"' EXIT
age --decrypt --identity "$age_identity_file" \
  --output "$temporary_directory/backup.tar.gz" "$encrypted_backup"
validate_archive_paths() {
  python3 - "$1" <<'PY'
import pathlib
import sys
import tarfile

with tarfile.open(sys.argv[1], "r:gz") as archive:
    for member in archive.getmembers():
        member_path = pathlib.PurePosixPath(member.name)
        if member_path.is_absolute() or ".." in member_path.parts:
            raise SystemExit("Archive contains an unsafe path; restore cancelled.")
        if not (member.isfile() or member.isdir()):
            raise SystemExit("Archive contains an unsupported file type; restore cancelled.")
PY
}
validate_archive_paths "$temporary_directory/backup.tar.gz"
tar -xzf "$temporary_directory/backup.tar.gz" -C "$temporary_directory"
service_names=(auth clients appointments interventions inventory billing accounting)

# Validate every target before importing any data.
for service_name in "${service_names[@]}"; do
  database_name="vicartech_${service_name}"
  dump_file="$temporary_directory/${database_name}.sql"
  [[ -s "$dump_file" ]] || { echo "Backup is missing a database dump." >&2; exit 1; }
  table_count="$(mariadb --defaults-extra-file="$mysql_defaults_file" \
    --batch --skip-column-names -e \
    "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${database_name}'")"
  [[ "$table_count" == 0 ]] || { echo "Target database is not empty; no restore performed." >&2; exit 1; }
done
for service_name in appointments billing; do
  storage_archive="$temporary_directory/${service_name}-private-storage.tar.gz"
  storage_directory="$private_storage_root/$service_name"
  [[ -s "$storage_archive" ]] || { echo "Backup is missing private document storage." >&2; exit 1; }
  validate_archive_paths "$storage_archive"
  tar -tzf "$storage_archive" >/dev/null
  if [[ -d "$storage_directory" ]] && find "$storage_directory" -mindepth 1 -print -quit | grep -q .; then
    echo "Private document storage is not empty; no restore performed." >&2
    exit 1
  fi
done

for service_name in "${service_names[@]}"; do
  database_name="vicartech_${service_name}"
  mariadb --defaults-extra-file="$mysql_defaults_file" "$database_name" \
    < "$temporary_directory/${database_name}.sql"
done

for service_name in appointments billing; do
  storage_archive="$temporary_directory/${service_name}-private-storage.tar.gz"
  storage_directory="$private_storage_root/$service_name"
  install -d -m 0750 "$storage_directory"
  tar -xzf "$storage_archive" -C "$storage_directory"
done

echo "Database and private document restore completed into empty targets. Verify ownership and run a restore rehearsal."
