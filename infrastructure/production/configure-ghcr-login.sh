#!/usr/bin/env bash
set -euo pipefail

if [[ -z "${GHCR_USER:-}" ]]; then
  read -r -p 'GitHub username with read:packages permission: ' GHCR_USER
fi
read -r -s -p 'Read-only GHCR token (input hidden): ' ghcr_token
printf '\n'
if [[ -z "$ghcr_token" ]]; then
  echo "A non-empty read-only token is required." >&2
  exit 1
fi
printf '%s' "$ghcr_token" | docker login ghcr.io --username "$GHCR_USER" --password-stdin >/dev/null
unset ghcr_token
if [[ -f "$HOME/.docker/config.json" ]]; then
  chmod 0600 "$HOME/.docker/config.json"
fi
echo "GHCR login configured for this account. The token was not printed."
