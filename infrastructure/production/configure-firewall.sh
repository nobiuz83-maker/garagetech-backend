#!/usr/bin/env bash
set -euo pipefail

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Run this script with sudo." >&2
  exit 1
fi
ssh_port="${SSH_PORT:-22}"
if [[ ! "$ssh_port" =~ ^[0-9]{1,5}$ ]] || (( ssh_port < 1 || ssh_port > 65535 )); then
  echo "SSH_PORT must be a valid TCP port." >&2
  exit 1
fi

ufw allow "$ssh_port/tcp" comment 'ViCarTech SSH'
ufw allow 80/tcp comment 'HTTP for ACME and redirect'
ufw allow 443/tcp comment 'HTTPS gateway'
ufw default deny incoming
ufw default allow outgoing
ufw --force enable

if ! iptables -nL DOCKER-USER >/dev/null 2>&1; then
  echo "Docker's DOCKER-USER chain is unavailable; install and start Docker before firewall setup." >&2
  exit 1
fi
external_interface="$(ip -o -4 route show to default | awk '{ print $5; exit }')"
if [[ -z "$external_interface" ]]; then
  echo "Cannot identify the public network interface." >&2
  exit 1
fi
iptables -C DOCKER-USER -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN 2>/dev/null \
  || iptables -I DOCKER-USER 1 -m conntrack --ctstate ESTABLISHED,RELATED -j RETURN
for public_port in 80 443; do
  iptables -C DOCKER-USER -i "$external_interface" -p tcp \
    -m conntrack --ctorigdstport "$public_port" -j RETURN 2>/dev/null \
    || iptables -I DOCKER-USER 2 -i "$external_interface" -p tcp \
      -m conntrack --ctorigdstport "$public_port" -j RETURN
done
iptables -C DOCKER-USER -i "$external_interface" -j DROP 2>/dev/null \
  || iptables -A DOCKER-USER -i "$external_interface" -j DROP
if command -v netfilter-persistent >/dev/null 2>&1; then
  netfilter-persistent save
else
  echo "Install iptables-persistent and save the DOCKER-USER rules for reboot persistence." >&2
  exit 1
fi
ufw status verbose
