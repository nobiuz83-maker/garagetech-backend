#!/usr/bin/env bash
set -euo pipefail

printf 'timestamp=%s\n' "$(date --iso-8601=seconds)"
awk '/MemTotal|MemAvailable|SwapTotal|SwapFree/ {print}' /proc/meminfo
printf '\nload_average='
cat /proc/loadavg
printf '\nfilesystem_usage:\n'
df -h / /srv /var/backups 2>/dev/null || df -h /
printf '\nprocess_memory:\n'
ps -eo comm,rss --sort=-rss | head -12
