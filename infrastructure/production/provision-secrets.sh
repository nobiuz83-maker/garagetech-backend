#!/usr/bin/env bash
set -euo pipefail
umask 077

production_root="${PRODUCTION_ROOT:-/opt/vicartech}"
script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
shared_directory="$production_root/shared"
secrets_directory="$shared_directory/secrets"
tls_directory="$secrets_directory/tls"

if [[ "$(id -u)" -eq 0 ]]; then
  echo "Run this as the dedicated deployment user, not root." >&2
  exit 1
fi
if [[ -e "$secrets_directory/mariadb_root_password" ]]; then
  echo "Production secrets already exist; refusing to replace them." >&2
  exit 1
fi

install -d -m 0700 "$shared_directory" "$secrets_directory" "$tls_directory" \
  "$tls_directory/ca-private" "$tls_directory/clients" "$tls_directory/servers"

create_hex_secret() {
  local secret_name="$1"
  openssl rand -hex 32 > "$secrets_directory/$secret_name"
  chmod 0400 "$secrets_directory/$secret_name"
}

create_app_key() {
  local secret_name="$1"
  printf 'base64:' > "$secrets_directory/$secret_name"
  openssl rand -base64 32 | tr -d '\n' >> "$secrets_directory/$secret_name"
  printf '\n' >> "$secrets_directory/$secret_name"
  chmod 0400 "$secrets_directory/$secret_name"
}

create_hex_secret mariadb_root_password
for service_name in auth clients appointments interventions inventory billing accounting; do
  create_app_key "${service_name}_app_key"
  create_hex_secret "${service_name}_db_password"
done

create_hex_secret internal_auth_clients_key
create_hex_secret internal_auth_appointments_key
create_hex_secret appointments_relationship_key
for service_name in clients appointments interventions inventory billing accounting; do
  create_hex_secret "v2_${service_name}_key"
done
create_hex_secret billing_inventory_key

openssl req -x509 -newkey rsa:3072 -nodes \
  -keyout "$tls_directory/ca-private/ca.key" \
  -out "$tls_directory/ca.crt" -days 3650 \
  -subj '/CN=ViCarTech Production Internal CA' \
  -addext 'basicConstraints=critical,CA:TRUE' \
  -addext 'keyUsage=critical,keyCertSign,cRLSign' >/dev/null 2>&1
chmod 0400 "$tls_directory/ca-private/ca.key"
chmod 0444 "$tls_directory/ca.crt"

for service_name in gateway auth-service clients-service appointments-service \
  interventions-service inventory-service billing-service accounting-service; do
  service_tls_directory="$tls_directory/$service_name"
  install -d -m 0700 "$service_tls_directory" "$service_tls_directory/current" \
    "$service_tls_directory/server"
  openssl req -new -newkey rsa:2048 -nodes \
    -keyout "$service_tls_directory/client.key" \
    -out "$service_tls_directory/request.csr" \
    -subj "/CN=$service_name" >/dev/null 2>&1
  printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth,clientAuth\nsubjectAltName=DNS:%s\n' "$service_name" \
    > "$service_tls_directory/extensions.cnf"
  openssl x509 -req -in "$service_tls_directory/request.csr" \
    -CA "$tls_directory/ca.crt" -CAkey "$tls_directory/ca-private/ca.key" \
    -CAcreateserial -days 365 -sha256 \
    -extfile "$service_tls_directory/extensions.cnf" \
    -out "$service_tls_directory/client.crt" >/dev/null 2>&1
  cp "$service_tls_directory/client.key" "$service_tls_directory/server.key"
  cp "$service_tls_directory/client.crt" "$service_tls_directory/server.crt"
  cp "$service_tls_directory/client.key" "$service_tls_directory/server/key.pem"
  cp "$service_tls_directory/client.crt" "$service_tls_directory/server/cert.pem"
  cp "$tls_directory/ca.crt" "$service_tls_directory/current/ca.pem"
  cp "$tls_directory/ca.crt" "$service_tls_directory/ca.crt"
  chmod 0444 "$service_tls_directory"/{client.key,client.crt,server.key,server.crt,ca.crt} \
    "$service_tls_directory/server"/{key.pem,cert.pem} \
    "$service_tls_directory/current/ca.pem"
  rm -f "$service_tls_directory/request.csr" "$service_tls_directory/extensions.cnf"
  chmod 0755 "$service_tls_directory"
  chmod 0755 "$service_tls_directory/server" "$service_tls_directory/current"
done

install -m 0600 "$script_directory/notification-provider.env.example" \
  "$shared_directory/notification-provider.env"
install -m 0600 "$script_directory/.env.example" \
  "$production_root/.env.production.example"

if command -v age-keygen >/dev/null 2>&1; then
  age-keygen -o "$shared_directory/backup-age-identity.txt" >/dev/null 2>&1
  chmod 0400 "$shared_directory/backup-age-identity.txt"
  age-keygen -y "$shared_directory/backup-age-identity.txt" \
    > "$shared_directory/backup-age-recipient.txt"
  chmod 0444 "$shared_directory/backup-age-recipient.txt"
else
  echo "Install age, then create an age key pair before scheduling backups." >&2
fi

printf 'Secrets and internal TLS identities created under %s.\n' "$shared_directory"
printf 'Edit %s/.env.production.example and rename it to .env.production.\n' "$production_root"
printf 'Set MAIL_PASSWORD manually in %s/notification-provider.env.\n' "$shared_directory"
printf 'Copy the public age recipient file off-server before configuring the backup destination.\n'
