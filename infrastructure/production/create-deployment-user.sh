#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script with sudo." >&2
  exit 1
fi
if [[ ! -s /etc/ssh/sshd_config.d/60-vicartech-production.conf ]]; then
  echo "Install Docker and verify SSH public-key access before creating the deployment account." >&2
  exit 1
fi
if ! getent group docker >/dev/null; then
  echo "Docker is not installed; refusing to create a deployment account without its runtime." >&2
  exit 1
fi

if ! id vicartech >/dev/null 2>&1; then
  useradd --create-home --shell /bin/bash vicartech
fi
install -d -m 0700 -o vicartech -g vicartech /home/vicartech/.ssh
if [[ ! -s /home/vicartech/.ssh/authorized_keys ]]; then
  echo "Install a dedicated CI deployment public key at /home/vicartech/.ssh/authorized_keys." >&2
  exit 1
fi
chown vicartech:vicartech /home/vicartech/.ssh/authorized_keys
chmod 0600 /home/vicartech/.ssh/authorized_keys
usermod --append --groups docker vicartech
install -d -m 0700 -o vicartech -g vicartech /opt/vicartech/shared /opt/vicartech/backups
printf 'Deployment account prepared. Membership in the docker group grants root-equivalent host access.\n'
