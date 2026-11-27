#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this installer with sudo." >&2
  exit 1
fi
if [[ ! -r /etc/os-release ]]; then
  echo "Cannot determine the operating system." >&2
  exit 1
fi
. /etc/os-release
if [[ "${ID:-}" != ubuntu ]]; then
  echo "This installer supports Ubuntu Server only." >&2
  exit 1
fi

architecture="$(dpkg --print-architecture)"
case "$architecture" in
  arm64|amd64) ;;
  *) echo "Unsupported Docker architecture: $architecture" >&2; exit 1 ;;
esac

apt-get update
apt-get install -y ca-certificates curl age rclone ufw openssh-server iptables-persistent
install -m 0755 -d /etc/apt/keyrings
curl --fail --silent --show-error --location https://download.docker.com/linux/ubuntu/gpg \
  -o /etc/apt/keyrings/docker.asc
chmod 0644 /etc/apt/keyrings/docker.asc
printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu %s stable\n' \
  "$architecture" "$VERSION_CODENAME" > /etc/apt/sources.list.d/docker.list
apt-get update
apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

install -d -m 0755 /etc/docker
if [[ ! -e /etc/docker/daemon.json ]]; then
  cat > /etc/docker/daemon.json <<'EOF'
{
  "log-driver": "local",
  "log-opts": {
    "max-size": "10m",
    "max-file": "5"
  }
}
EOF
  chmod 0644 /etc/docker/daemon.json
fi
systemctl enable --now docker
docker version --format '{{.Server.Arch}}'
docker compose version
