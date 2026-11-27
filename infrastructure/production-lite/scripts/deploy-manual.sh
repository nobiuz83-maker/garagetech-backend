#!/usr/bin/env bash
set -euo pipefail

release_directory="${1:-}"
if [[ -z "$release_directory" || ! -d "$release_directory" ]]; then
  echo "Usage: $0 /srv/vicartech/releases/RELEASE_ID" >&2
  exit 2
fi
if [[ ! -f "$release_directory/services/auth-service/artisan" ]]; then
  echo "Release directory does not contain all ViCarTech service sources." >&2
  exit 1
fi

service_names=(auth clients appointments interventions inventory billing accounting)
for service_name in "${service_names[@]}"; do
  service_directory="$release_directory/services/${service_name}-service"
  if [[ ! -f "$service_directory/artisan" || ! -f "$service_directory/composer.lock" ]]; then
    echo "Required source or Composer lock file is missing for ${service_name}." >&2
    exit 1
  fi
done

cat <<EOF
Release layout validated: $release_directory
No server operation or database migration was performed.
Review and perform the documented manual sequence in infrastructure/production-lite/README.md.
EOF
