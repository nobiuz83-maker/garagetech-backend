#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script with sudo." >&2
  exit 1
fi
if [[ -z "${SUDO_USER:-}" || "$SUDO_USER" == root ]]; then
  echo "Use sudo from the non-root SSH account that has a tested authorized key." >&2
  exit 1
fi
authorized_keys="$(getent passwd "$SUDO_USER" | cut -d: -f6)/.ssh/authorized_keys"
if [[ ! -s "$authorized_keys" ]]; then
  echo "No authorized SSH public key found for $SUDO_USER; refusing to disable password login." >&2
  exit 1
fi

configuration_file=/etc/ssh/sshd_config.d/60-vicartech-production.conf
install -d -m 0755 /etc/ssh/sshd_config.d
cat > "$configuration_file" <<'EOF'
PermitRootLogin no
PasswordAuthentication no
KbdInteractiveAuthentication no
PubkeyAuthentication yes
AllowTcpForwarding no
X11Forwarding no
EOF
chmod 0644 "$configuration_file"
sshd -t
systemctl reload ssh
echo "SSH hardening loaded. Keep the current session open until a new key login is verified."
