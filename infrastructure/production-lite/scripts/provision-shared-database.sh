#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this provisioning script as root on the PRODUCTION-LITE host." >&2
  exit 1
fi

script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
configuration_directory="${VICARTECH_CONFIG_DIRECTORY:-/etc/vicartech}"
service_environment_directory="$configuration_directory/services"
secret_directory="$configuration_directory/secrets"
password_file="$secret_directory/database-password"
sql_template="$script_directory/../mariadb/shared-user-provisioning.sql.template"

command -v mariadb >/dev/null || { echo "MariaDB client is not installed." >&2; exit 1; }
command -v openssl >/dev/null || { echo "OpenSSL is required to generate the database password." >&2; exit 1; }
command -v python3 >/dev/null || { echo "Python 3 is required to update private service environments." >&2; exit 1; }
command -v flock >/dev/null || { echo "flock is required to prevent concurrent provisioning." >&2; exit 1; }
[[ -r "$sql_template" ]] || { echo "The shared-user SQL template is missing." >&2; exit 1; }

install -d -o root -g root -m 0711 "$configuration_directory" "$service_environment_directory"
install -d -o root -g root -m 0700 "$secret_directory"
install -d -o root -g root -m 0700 /run/lock
exec 9>/run/lock/vicartech-shared-database-provision.lock
flock -n 9 || { echo "Another PRODUCTION-LITE database provisioning process is running." >&2; exit 1; }
temporary_password_file=""
temporary_sql_file=""
trap '[[ -z "$temporary_password_file" ]] || rm -f "$temporary_password_file"; [[ -z "$temporary_sql_file" ]] || rm -f "$temporary_sql_file"' EXIT

python3 - "$service_environment_directory" <<'PY'
import pathlib
import pwd
import sys

service_names = ("auth", "clients", "appointments", "interventions", "inventory", "billing", "accounting")
environment_directory = pathlib.Path(sys.argv[1])
if environment_directory.is_symlink() or environment_directory.stat().st_uid != 0:
    raise SystemExit("The private service environment directory must be a real root-owned directory.")
if environment_directory.stat().st_mode & 0o777 != 0o711:
    raise SystemExit("Set the private service environment directory to root-owned mode 0711.")
for service_name in service_names:
    service_user = f"vicartech-{service_name}"
    service_account = pwd.getpwnam(service_user)
    environment_file = environment_directory / f"{service_name}.env"
    if environment_file.is_symlink() or not environment_file.is_file():
        raise SystemExit(f"A regular private environment file is required for {service_name}.")
    if environment_file.stat().st_uid != service_account.pw_uid:
        raise SystemExit(f"The {service_name} environment file must be owned by its service account.")
    if environment_file.stat().st_mode & 0o077:
        raise SystemExit(f"The {service_name} environment file must not be readable by group or other users.")
PY

if [[ -e "$password_file" ]]; then
  if [[ ! -f "$password_file" || -L "$password_file" || "$(stat -c '%a' "$password_file")" != 600 ]]; then
    echo "The existing database password file must be a regular mode-0600 file." >&2
    exit 1
  fi
  if ! grep -Eq '^[a-f0-9]{64}$' "$password_file"; then
    echo "The existing database password file has an invalid format." >&2
    exit 1
  fi
else
  temporary_password_file="$(mktemp "$secret_directory/.database-password.XXXXXX")"
  openssl rand -hex 32 > "$temporary_password_file"
  chmod 0600 "$temporary_password_file"
  mv "$temporary_password_file" "$password_file"
  temporary_password_file=""
fi

# MariaDB is contacted only through the local Unix socket; remote hosts are not accepted.
role_assignment_count="$(mariadb --no-defaults --protocol=socket --user=root --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM mysql.roles_mapping WHERE User='vicartech' AND Host='127.0.0.1'")"
if [[ "$role_assignment_count" != 0 ]]; then
  echo "The reserved vicartech database account has role assignments; review them before provisioning." >&2
  exit 1
fi
proxy_assignment_count="$(mariadb --no-defaults --protocol=socket --user=root --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM mysql.proxies_priv WHERE User='vicartech' AND Host='127.0.0.1'")"
if [[ "$proxy_assignment_count" != 0 ]]; then
  echo "The reserved vicartech database account has proxy grants; review them before provisioning." >&2
  exit 1
fi
other_account_count="$(mariadb --no-defaults --protocol=socket --user=root --batch --skip-column-names \
  --execute="SELECT COUNT(*) FROM mysql.global_priv WHERE User='vicartech' AND Host<>'127.0.0.1'")"
if [[ "$other_account_count" != 0 ]]; then
  echo "Other MariaDB accounts named vicartech exist; review them before provisioning." >&2
  exit 1
fi

temporary_sql_file="$(mktemp "$secret_directory/.shared-user-provisioning.XXXXXX.sql")"
chmod 0600 "$temporary_sql_file"
python3 - "$sql_template" "$password_file" "$temporary_sql_file" <<'PY'
import pathlib
import re
import sys

template_path, password_path, output_path = map(pathlib.Path, sys.argv[1:])
database_password = password_path.read_text().strip()
if not re.fullmatch(r"[a-f0-9]{64}", database_password):
    raise SystemExit("The stored database password does not match the expected generated format.")
template = template_path.read_text()
placeholder = "__VICARTECH_DATABASE_PASSWORD__"
if template.count(placeholder) != 2:
    raise SystemExit("The shared-user SQL template must contain exactly two password markers.")
output_path.write_text(template.replace(placeholder, database_password))
output_path.chmod(0o600)
PY

mariadb --no-defaults --protocol=socket --user=root < "$temporary_sql_file"

backup_directory="$secret_directory/service-environment-backups/$(date -u +%Y%m%dT%H%M%SZ)"
install -d -o root -g root -m 0700 "$backup_directory"
python3 - "$service_environment_directory" "$password_file" "$backup_directory" <<'PY'
import os
import pathlib
import pwd
import re
import shutil
import sys
import tempfile

environment_directory, password_path, backup_directory = map(pathlib.Path, sys.argv[1:])
database_password = password_path.read_text().strip()
if not re.fullmatch(r"[a-f0-9]{64}", database_password):
    raise SystemExit("The stored database password does not match the expected generated format.")

service_databases = {
    "auth": "vicartech_auth",
    "clients": "vicartech_clients",
    "appointments": "vicartech_appointments",
    "interventions": "vicartech_interventions",
    "inventory": "vicartech_inventory",
    "billing": "vicartech_billing",
    "accounting": "vicartech_accounting",
}
for service_name, database_name in service_databases.items():
    service_user = f"vicartech-{service_name}"
    service_account = pwd.getpwnam(service_user)
    environment_file = environment_directory / f"{service_name}.env"
    shutil.copyfile(environment_file, backup_directory / f"{service_name}.env")
    (backup_directory / f"{service_name}.env").chmod(0o600)

    database_values = {
        "DB_CONNECTION": "mysql",
        "DB_HOST": "127.0.0.1",
        "DB_PORT": "3306",
        "DB_DATABASE": database_name,
        "DB_USERNAME": "vicartech",
        "DB_PASSWORD": database_password,
        "DB_CHARSET": "utf8mb4",
        "DB_COLLATION": "utf8mb4_unicode_ci",
    }
    updated_lines = []
    updated_keys = set()
    for line in environment_file.read_text().splitlines():
        key, separator, _value = line.partition("=")
        if separator and key in database_values:
            if key not in updated_keys:
                updated_lines.append(f"{key}={database_values[key]}")
                updated_keys.add(key)
        else:
            updated_lines.append(line)
    for key, value in database_values.items():
        if key not in updated_keys:
            updated_lines.append(f"{key}={value}")

    with tempfile.NamedTemporaryFile(mode="w", dir=environment_directory, prefix=f".{service_name}.env.", delete=False) as temporary_file:
        temporary_file.write("\n".join(updated_lines) + "\n")
        temporary_path = pathlib.Path(temporary_file.name)
    temporary_path.chmod(0o600)
    os.chown(temporary_path, service_account.pw_uid, service_account.pw_gid)
    os.replace(temporary_path, environment_file)
PY

rm -f "$temporary_sql_file"
trap - EXIT
echo "Seven local databases and the shared vicartech account are provisioned. The generated password is stored privately; no password value was printed."
