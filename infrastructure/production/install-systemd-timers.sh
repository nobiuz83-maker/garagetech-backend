#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script with sudo." >&2
  exit 1
fi
if ! id vicartech >/dev/null 2>&1; then
  echo "Create the dedicated vicartech deployment account first." >&2
  exit 1
fi

script_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
for unit_name in vicartech-backup.service vicartech-backup.timer \
  vicartech-certificates.service vicartech-certificates.timer; do
  install -m 0644 "$script_directory/systemd/$unit_name" "/etc/systemd/system/$unit_name"
done
for unit_name in vicartech-backup.service vicartech-certificates.service; do
  sed -i '/^Group=vicartech$/a SupplementaryGroups=docker' "/etc/systemd/system/$unit_name"
done
systemctl daemon-reload
systemctl enable --now vicartech-backup.timer vicartech-certificates.timer
systemctl list-timers --all 'vicartech-*'
