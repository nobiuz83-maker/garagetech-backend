#!/usr/bin/env bash
set -euo pipefail

profile="${1:-}"
action="${2:-}"
profile_environment="${3:-/etc/vicartech/profiles/${profile}.env}"
backend_directory="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

if [[ "$profile" != office && "$profile" != home ]]; then
  echo "Choose office or home." >&2
  exit 2
fi
if [[ "$action" != config && "$action" != up && "$action" != down ]]; then
  echo "Choose config, up or down." >&2
  exit 2
fi
if [[ ! -f "$profile_environment" ]]; then
  echo "Profile environment file is missing: $profile_environment" >&2
  exit 2
fi
if [[ "$action" == up && "${VICARTECH_WORKFLOW_SECURITY_READY:-}" != YES ]]; then
  echo "Complete and verify Auth v2 and the service-to-service TLS configuration before starting this stack." >&2
  exit 2
fi
if [[ "$profile" == home && "$action" == up && "${VICARTECH_HOME_ACTIVATION_APPROVED:-}" != YES ]]; then
  echo "HOME activation requires an explicit approval and VICARTECH_HOME_ACTIVATION_APPROVED=YES." >&2
  exit 2
fi

compose=(docker compose --env-file "$profile_environment" \
  --profile gateway --profile auth --profile clients --profile appointments \
  --profile interventions --profile inventory --profile billing --profile accounting \
  -f "$backend_directory/compose.yaml" -f "$backend_directory/lan.compose.yaml")

configuration_file="$(mktemp)"
trap 'rm -f "$configuration_file"' EXIT
"${compose[@]}" config --format json > "$configuration_file"
python3 - "$configuration_file" "$profile" "$action" <<'PY'
import json
import pathlib
import sys
from urllib.parse import urlparse

configuration = json.loads(pathlib.Path(sys.argv[1]).read_text())
profile = sys.argv[2]
action = sys.argv[3]
expected_project = f'vicartech-{profile}'
expected_volume = f'vicartech_{profile}_database_data'
assert configuration['name'] == expected_project, 'Unexpected Compose project name'
assert configuration['volumes']['stage1_database_data']['name'] == expected_volume, 'Unexpected database volume'
assert all(network['internal'] for network in configuration['networks'].values()), 'A Docker network is not internal'
services = configuration['services']
auth_environment = services['auth-service']['environment']
origin = auth_environment['FRONTEND_URL']
parsed_origin = urlparse(origin)
assert parsed_origin.scheme == 'https' and parsed_origin.hostname and not parsed_origin.path, 'The frontend origin must use HTTPS'
assert auth_environment['APP_URL'] == origin, 'APP_URL and FRONTEND_URL must match'
expected_stateful_domain = parsed_origin.hostname + (f':{parsed_origin.port}' if parsed_origin.port and parsed_origin.port != 443 else '')
assert auth_environment['SANCTUM_STATEFUL_DOMAINS'] == expected_stateful_domain, 'Sanctum domain must match the browser origin'
assert parsed_origin.port == 8443 if profile == 'office' else parsed_origin.port in (None, 443), 'Unexpected HTTPS origin port'
assert len(services) == 9, 'Expected Gateway, database and seven Laravel services'
assert all(not service.get('ports') for name, service in services.items() if name != 'gateway'), 'An internal service publishes a port'
ports = services['gateway']['ports']
assert len(ports) == 1 and ports[0]['target'] == 8443 and ports[0]['protocol'] == 'tcp', 'Unexpected Gateway publication'
expected_address = '127.0.0.1' if profile == 'office' else None
expected_port = '8443' if profile == 'office' else '443'
assert ports[0]['published'] == expected_port, 'Unexpected HTTPS host port'
if expected_address:
    assert ports[0]['host_ip'] == expected_address, 'OFFICE may only bind loopback'
else:
    assert ports[0]['host_ip'] not in ('', '0.0.0.0', '127.0.0.1'), 'HOME requires a reserved LAN IP'
assert any('/run/secrets/lan_server_cert' in str(item) for item in services['gateway']['secrets']), 'Missing HTTPS certificate'
if action == 'up':
    assert all(service['environment'].get('APP_DEBUG') == 'false' for name, service in services.items() if name.endswith('-service')), 'APP_DEBUG must be false'
    for secret in configuration['secrets'].values():
        source = pathlib.Path(secret['file'])
        assert source.is_file() and '/absolute/' not in str(source), f'Missing private secret: {source}'
    for mount in services['gateway']['volumes']:
        if mount['target'] == '/usr/share/nginx/html':
            assert (pathlib.Path(mount['source']) / 'index.html').is_file(), 'Profile frontend build is missing'
print(f'{profile.upper()}: Compose topology validated')
PY

case "$action" in
  config) ;;
  up) "${compose[@]}" up --detach ;;
  down) "${compose[@]}" stop ;;
esac
