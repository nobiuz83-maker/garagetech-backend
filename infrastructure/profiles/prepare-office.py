"""Prepare a persistent, isolated OFFICE deployment from the existing synthetic generator.

The source generator creates fresh credentials, certificates and seven database
volumes. This adapter keeps its validated security model and enables the
already implemented business routes for a localhost-only office instance.
"""
import json
import copy
from pathlib import Path
import subprocess

BACKEND = Path(__file__).resolve().parents[2]
WORKSPACE = BACKEND / '.private' / 'office'
PROJECT = 'vicartech-lot1-integration-office'
ORIGIN = 'https://localhost:8443'
FRONTEND = WORKSPACE / 'frontend'


def update_service(configuration: dict, service_name: str, settings: dict[str, str]) -> None:
    configuration['services'][service_name]['environment'].update(settings)


def prepare_office() -> None:
    if WORKSPACE.exists():
        raise SystemExit('OFFICE workspace already exists; refusing to overwrite secrets or data.')
    WORKSPACE.parent.mkdir(mode=0o700, exist_ok=True)
    WORKSPACE.parent.chmod(0o700)
    subprocess.run([
        'python3', str(BACKEND / 'infrastructure/docker/prepare-company-integration.py'),
        '--workspace', str(WORKSPACE), '--frontend', str(FRONTEND),
    ], check=True, capture_output=True, text=True)
    configuration_path = WORKSPACE / 'compose.json'
    configuration = json.loads(configuration_path.read_text())
    (WORKSPACE / 'notification-logos').mkdir(mode=0o750)
    (WORKSPACE / 'appointments-service/storage/app/private/notification-logos').mkdir(mode=0o750, parents=True)
    provider_configuration = WORKSPACE / 'notification-provider.env'
    provider_configuration.write_text(
        'MAIL_MAILER=log\nMAIL_HOST=\nMAIL_PORT=587\nMAIL_USERNAME=\nMAIL_PASSWORD=\nMAIL_SCHEME=tls\n'
        'MAIL_FROM_ADDRESS=\nMAIL_FROM_NAME=ViCarTech\nTWILIO_ACCOUNT_SID=\nTWILIO_AUTH_TOKEN=\nTWILIO_FROM_NUMBER=\n'
    )
    provider_configuration.chmod(0o600)
    configuration['name'] = PROJECT
    for index, network in enumerate(sorted(configuration['networks'])):
        configuration['networks'][network]['ipam'] = {'config': [{'subnet': f'10.249.71.{index * 16}/28'}]}
    for service in configuration['services'].values():
        environment = service.get('environment', {})
        if 'VICARTECH_INTEGRATION_PROJECT' in environment:
            environment['VICARTECH_INTEGRATION_PROJECT'] = PROJECT
        if environment.get('APP_URL') == 'https://localhost:5843':
            environment['APP_URL'] = ORIGIN
        if environment.get('FRONTEND_URL') == 'https://localhost:5843':
            environment['FRONTEND_URL'] = ORIGIN
        if environment.get('SANCTUM_STATEFUL_DOMAINS') == 'localhost:5843':
            environment['SANCTUM_STATEFUL_DOMAINS'] = 'localhost:8443'
    configuration['services']['gateway']['ports'] = ['127.0.0.1:8443:8443']
    for name, service in configuration['services'].items():
        if name.endswith('-service'):
            service['healthcheck']['test'] = [
                'CMD', 'curl', '--fail', '--silent', '--show-error',
                '--cacert', '/run/integration/tls/ca.pem',
                '--cert', '/run/integration/tls/client/cert.pem',
                '--key', '/run/integration/tls/client/key.pem',
                '--resolve', f'{name}:8443:127.0.0.1',
                f'https://{name}:8443/api/health',
            ]
    appointment_service = configuration['services']['appointments-service']
    appointment_service['environment'].update({'QUEUE_CONNECTION': 'database', 'FRONTEND_URL': ORIGIN})
    appointment_service['env_file'] = ['notification-provider.env']
    notification_logo_mount = f'{WORKSPACE / "notification-logos"}:/var/www/html/storage/app/private/notification-logos'
    appointment_service['volumes'].append(notification_logo_mount)
    appointment_service['entrypoint'][2] = appointment_service['entrypoint'][2].replace(
        'mkdir -p storage/framework/sessions', 'mkdir -p storage/app/private/notification-logos storage/framework/sessions'
    )
    for service_name, command in [
        ('appointments-notification-worker', 'exec php artisan queue:work database --queue=notifications --tries=4 --timeout=90 --sleep=2'),
        ('appointments-scheduler', 'exec php artisan schedule:work --verbose --no-interaction'),
    ]:
        process = copy.deepcopy(appointment_service)
        process.pop('healthcheck', None)
        process['entrypoint'] = ['/bin/sh', '-c', command]
        process['depends_on'] = {'appointments-service': {'condition': 'service_healthy'}}
        process['profiles'] = ['appointments']
        process['restart'] = 'unless-stopped'
        configuration['services'][service_name] = process
    update_service(configuration, 'interventions-service', {
        'INTERVENTION_CLIENTS_URL': 'https://clients-service:8443',
        'INTERVENTION_CLIENTS_CA_FILE': '/run/integration/tls/ca.pem',
        'INTERVENTION_CLIENTS_CERT_FILE': '/run/integration/tls/client/cert.pem',
        'INTERVENTION_CLIENTS_KEY_FILE': '/run/integration/tls/client/key.pem',
    })
    update_service(configuration, 'inventory-service', {
        'INVENTORY_INTERVENTIONS_URL': 'https://interventions-service:8443',
        'INVENTORY_INTERVENTIONS_CA_FILE': '/run/integration/tls/ca.pem',
        'INVENTORY_INTERVENTIONS_CERT_FILE': '/run/integration/tls/client/cert.pem',
        'INVENTORY_INTERVENTIONS_KEY_FILE': '/run/integration/tls/client/key.pem',
        'INVENTORY_BILLING_URL': 'https://billing-service:8443',
        'INVENTORY_TO_BILLING_SIGNING_KEY_FILE': '/run/integration/keys/billing-to-inventory.key',
        'INVENTORY_BILLING_KEY_FILE': '/run/integration/keys/billing-to-inventory.key',
    })
    update_service(configuration, 'billing-service', {
        'BILLING_INTERVENTIONS_URL': 'https://interventions-service:8443',
        'BILLING_INTERVENTIONS_CA_FILE': '/run/integration/tls/ca.pem',
        'BILLING_INTERVENTIONS_CERT_FILE': '/run/integration/tls/client/cert.pem',
        'BILLING_INTERVENTIONS_KEY_FILE': '/run/integration/tls/client/key.pem',
        'BILLING_INVENTORY_URL': 'https://inventory-service:8443',
        'BILLING_INVENTORY_CA_FILE': '/run/integration/tls/ca.pem',
        'BILLING_INVENTORY_CERT_FILE': '/run/integration/tls/client/cert.pem',
        'BILLING_INVENTORY_KEY_FILE': '/run/integration/tls/client/key.pem',
        'BILLING_TO_INVENTORY_SIGNING_KEY_FILE': '/run/integration/keys/billing-to-inventory.key',
        'BILLING_INVENTORY_KEY_FILE': '/run/integration/keys/billing-to-inventory.key',
    })
    for name in ('inventory', 'billing'):
        configuration['services'][f'{name}-service']['volumes'].append(
            f'{WORKSPACE / "keys/billing-to-inventory.key"}:/run/integration/keys/billing-to-inventory.key:ro'
        ) if not any('billing-to-inventory.key:' in mount for mount in configuration['services'][f'{name}-service']['volumes']) else None
    gateway_config = WORKSPACE / 'gateway.conf'
    content = gateway_config.read_text().replace(' ~^(.{8})(.{4})(.{4})(.{4})(.{12})$ $1-$2-$3-$4-$5;', ' \"~^(.{8})(.{4})(.{4})(.{4})(.{12})$\" $1-$2-$3-$4-$5;')
    marker = ' location /api/ { return 404; }'
    business_routes = ''' location ~ ^/api/v1/(?:appointments|appointment-schedule|appointment-availability|appointment-notification-[^/]+|public/appointment-cancellations)(?:/|$) { include /etc/nginx/appointments-proxy.conf; }
 location ~ ^/api/v1/interventions(?:/|$) { include /etc/nginx/interventions-proxy.conf; }
 location ~ ^/api/v1/inventory(?:/|$) { include /etc/nginx/inventory-proxy.conf; }
 location ~ ^/api/v1/invoices(?:/|$) { include /etc/nginx/billing-proxy.conf; }
'''
    if marker not in content:
        raise RuntimeError('Generated Gateway route marker missing')
    gateway_config.write_text(content.replace(marker, business_routes + marker))
    configuration_path.write_text(json.dumps(configuration, indent=2))
    configuration_path.chmod(0o600)
    (WORKSPACE / 'accounts.json').chmod(0o600)
    print(f'Prepared isolated OFFICE configuration at {WORKSPACE}; no containers started.')


if __name__ == '__main__':
    prepare_office()
