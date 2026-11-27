"""Prepare a NEW seven-service, seven-database synthetic integration deployment.

Only generated configuration is written. Existing Compose projects and volumes are never reused.
"""
import argparse
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import uuid

BACKEND = Path(__file__).resolve().parents[2]
SERVICES = ['auth', 'clients', 'appointments', 'inventory', 'interventions', 'billing', 'accounting']


def prepare(workspace, frontend):
    if workspace.exists():
        raise ValueError('A fresh workspace is required; existing files are never overwritten')
    os.umask(0o077)
    workspace.mkdir(mode=0o700, parents=True)
    project = 'vicartech-lot1-integration-20261117'
    module_spec = importlib.util.spec_from_file_location('https_fixture', BACKEND/'infrastructure/docker/configure-auth-https.py')
    certificate_tools = importlib.util.module_from_spec(module_spec)
    module_spec.loader.exec_module(certificate_tools)
    authority = workspace/'authority'
    authority.mkdir()
    certificate_tools.openssl('req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-keyout', authority/'key.pem', '-out', authority/'cert.pem', '-days', 365,
                              '-subj', '/CN=ViCarTech LOT1 SYNTHETIC integration CA', '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign')
    for name in [*SERVICES, 'gateway']:
        identity = name+'-service' if name != 'gateway' else name
        tls = workspace/'tls'/identity
        tls.mkdir(parents=True)
        shutil.copyfile(authority/'cert.pem', tls/'ca.pem')
        certificate_tools.issue_certificate(authority, tls/'client', identity)
        certificate_tools.issue_certificate(authority, tls/'server', identity, 'DNS:'+identity)
    certificate_tools.issue_certificate(authority, workspace/'tls/gateway/browser', 'localhost', 'DNS:localhost,IP:127.0.0.1')
    certificate_tools.issue_certificate(authority, workspace/'tls/rogue', 'untrusted')
    certificate_tools.issue_certificate(authority, workspace/'tls/expired', 'billing-service', days=0)
    (workspace/'keys').mkdir()
    for name in [*SERVICES, 'billing-to-inventory', 'appointments-to-clients']:
        (workspace/'keys'/(name+'.key')).write_text(secrets.token_hex(32))
    companies = [{'id': str(uuid.uuid4()), 'name': 'Synthetic company '+label} for label in ['A', 'B']]
    accounts = [
        {'name': 'Synthetic Owner A', 'email': 'owner-a@example.invalid', 'password': secrets.token_urlsafe(24), 'memberships': [{'companyId': companies[0]['id'], 'role': 'owner'}]},
        {'name': 'Synthetic Colleague', 'email': 'colleague@example.invalid', 'password': secrets.token_urlsafe(24), 'memberships': [{'companyId': companies[0]['id'], 'role': 'technician'}, {'companyId': companies[1]['id'], 'role': 'accountant'}]},
        {'name': 'Synthetic Owner B', 'email': 'owner-b@example.invalid', 'password': secrets.token_urlsafe(24), 'memberships': [{'companyId': companies[1]['id'], 'role': 'owner'}]},
    ]
    (workspace/'accounts.json').write_text(json.dumps({'companies': companies, 'accounts': accounts}, indent=2))
    runtime_dockerfile = workspace/'Runtime.Dockerfile'
    runtime_dockerfile.write_text('FROM vicartech-laravel-runtime:stage1\nUSER root\nRUN a2enmod ssl\nUSER 1000:1000\n')
    configuration = {'name': project, 'services': {}, 'networks': {'mesh': {'internal': True}, 'frontend': {}}, 'volumes': {}}
    for name in SERVICES:
        service_name = name+'-service'
        database_name = 'auth_db' if name == 'auth' else 'vicartech_integration_'+name
        database_password = secrets.token_urlsafe(32)
        data_network = 'data_'+name
        data_volume = 'integration_'+name+'_data'
        configuration['networks'][data_network] = {'internal': True}
        configuration['volumes'][data_volume] = {}
        configuration['services']['database-'+name] = {
            'image': 'mariadb:10.6.28-ubi9', 'pull_policy': 'never',
            'environment': {'MARIADB_ROOT_PASSWORD': secrets.token_urlsafe(32), 'MARIADB_DATABASE': database_name,
                            'MARIADB_USER': 'integration_'+name, 'MARIADB_PASSWORD': database_password},
            'volumes': [data_volume+':/var/lib/mysql'], 'networks': [data_network],
            'healthcheck': {'test': ['CMD', 'healthcheck.sh', '--connect', '--innodb_initialized'], 'interval': '2s', 'timeout': '3s', 'retries': 40},
        }
        service_directory = workspace/service_name
        service_directory.mkdir()
        for path in ['app', 'config', 'database', 'routes', 'resources', 'tests', 'public']:
            if (BACKEND/'services'/service_name/path).exists():
                shutil.copytree(BACKEND/'services'/service_name/path, service_directory/path)
        shutil.copytree(BACKEND/'services'/service_name/'bootstrap', service_directory/'bootstrap', ignore=shutil.ignore_patterns('cache'))
        (service_directory/'bootstrap/cache').mkdir()
        (service_directory/'storage').mkdir()
        for path in ['artisan', 'composer.json', 'composer.lock']:
            shutil.copyfile(BACKEND/'services'/service_name/path, service_directory/path)
        (service_directory/'vendor').mkdir()
        (service_directory/'public/.htaccess').write_text((BACKEND/'services'/service_name/'public/.htaccess').read_text())
        key = 'base64:'+base64.b64encode(secrets.token_bytes(32)).decode()
        environment = {'APP_ENV': 'integration', 'APP_DEBUG': 'false', 'APP_KEY': key, 'APP_URL': 'https://localhost:5843',
                       'DB_CONNECTION': 'mysql', 'DB_HOST': 'database-'+name, 'DB_DATABASE': database_name,
                       'DB_USERNAME': 'integration_'+name, 'DB_PASSWORD': database_password,
                       'CACHE_STORE': 'database' if name in ['auth', 'clients', 'appointments'] else 'array', 'QUEUE_CONNECTION': 'sync',
                       'SESSION_DRIVER': 'database' if name == 'auth' else 'array', 'LOG_CHANNEL': 'stderr',
                       'VICARTECH_SYNTHETIC_INTEGRATION': 'true', 'VICARTECH_INTEGRATION_PROJECT': project,
                       'COMPANY_SECURITY_ENABLED': 'true', 'SESSION_SERIALIZATION': 'json'}
        mounts = [str(service_directory)+':/var/www/html:ro', str(BACKEND/'services'/service_name/'vendor')+':/var/www/html/vendor:ro',
                  str(workspace/'tls'/service_name)+':/run/integration/tls:ro', str(workspace/'keys')+':/run/integration/keys:ro',
                  str(BACKEND/'infrastructure/docker/fixtures')+':/run/integration/fixtures:ro']
        # Each isolated runtime receives only its own HMAC files below; remove the broad preparation mount.
        mounts.remove(str(workspace/'keys')+':/run/integration/keys:ro')
        mounts.append(str(workspace/'keys'/(name+'.key'))+':/run/integration/keys/auth.key:ro')
        if name == 'auth':
            environment.update({'SESSION_COOKIE': 'vicartech-auth-session', 'SESSION_DOMAIN': 'null', 'SESSION_PATH': '/',
                                'SESSION_SECURE_COOKIE': 'true', 'SESSION_HTTP_ONLY': 'true', 'SESSION_SAME_SITE': 'lax',
                                'SANCTUM_STATEFUL_DOMAINS': 'localhost:5843', 'FRONTEND_URL': 'https://localhost:5843',
                                'INTERNAL_AUTH_CLIENTS_KEY_FILE': '/run/integration/keys/clients.key', 'INTERNAL_AUTH_APPOINTMENTS_KEY_FILE': '/run/integration/keys/appointments.key'})
            for caller in ['clients', 'appointments', 'inventory', 'interventions', 'billing', 'accounting']:
                mounts.append(str(workspace/'keys'/(caller+'.key'))+':/run/integration/keys/'+caller+'.key:ro')
                if caller in ['inventory', 'interventions', 'billing', 'accounting']:
                    environment['INTERNAL_AUTH_V2_'+caller.upper()+'_KEY_FILE']='/run/integration/keys/'+caller+'.key'
        if name in ['clients', 'appointments']:
            environment.update({'AUTH_SERVICE_URL': 'https://auth-service:8443', 'AUTH_CA_CERTIFICATE_FILE': '/run/integration/tls/ca.pem',
                                'AUTH_CLIENT_CERTIFICATE_FILE': '/run/integration/tls/client/cert.pem', 'AUTH_CLIENT_KEY_FILE': '/run/integration/tls/client/key.pem',
                                'AUTH_SIGNING_KEY_FILE': '/run/integration/keys/auth.key'})
            mounts.append(str(workspace/'keys/appointments-to-clients.key')+':/run/integration/keys/relationship.key:ro')
            environment.update({'CLIENTS_SERVICE_URL': 'https://clients-service:8443', 'CLIENTS_RELATIONSHIP_KEY_FILE': '/run/integration/keys/relationship.key', 'APPOINTMENTS_RELATIONSHIP_KEY_FILE': '/run/integration/keys/relationship.key'})
        if name in ['inventory', 'interventions', 'billing', 'accounting']:
            environment.update({'AUTH_SERVICE_V2_URL': 'https://auth-service:8443', 'AUTH_V2_CA_CERTIFICATE_FILE': '/run/integration/tls/ca.pem',
                                'AUTH_V2_CLIENT_CERTIFICATE_FILE': '/run/integration/tls/client/cert.pem', 'AUTH_V2_CLIENT_KEY_FILE': '/run/integration/tls/client/key.pem',
                                'AUTH_V2_SIGNING_KEY_FILE': '/run/integration/keys/auth.key'})
            routes = service_directory/'routes/api.php'
            routes.write_text(routes.read_text()+"\nrequire '/run/integration/fixtures/company-security-probes.php';\n")
        if name in ['inventory', 'billing']:
            mounts.append(str(workspace/'keys/billing-to-inventory.key')+':/run/integration/keys/billing-to-inventory.key:ro')
        if name == 'inventory':
            config_file = service_directory/'config/company_security.php'
            config_file.write_text(config_file.read_text().replace("'callers' => []", "'callers' => ['billing-service' => ['key_file' => '/run/integration/keys/billing-to-inventory.key', 'capabilities' => ['integration.identity']]]"))
        apache = service_directory/'apache.conf'
        apache.write_text('''ServerName localhost
PidFile /tmp/apache2/apache2.pid
LogFormat "%a %m %U %>s %D %{X-Request-ID}i" request_summary
<VirtualHost *:8080>
 DocumentRoot /var/www/html/public
 <Directory /var/www/html/public>
  AllowOverride All
  Require all granted
 </Directory>
 <LocationMatch "^/(?!api/health$|up$)">
  Require all denied
 </LocationMatch>
 ErrorLog /dev/stderr
 CustomLog /dev/stdout request_summary
</VirtualHost>
Listen 8443
<VirtualHost *:8443>
 ServerName '''+service_name+'''
 DocumentRoot /var/www/html/public
 <Directory /var/www/html/public>
  AllowOverride All
  Require all granted
  Options -Indexes +FollowSymLinks
 </Directory>
 SSLEngine on
 SSLCertificateFile /run/integration/tls/server/cert.pem
 SSLCertificateKeyFile /run/integration/tls/server/key.pem
 SSLCACertificateFile /run/integration/tls/ca.pem
 SSLVerifyClient require
 SSLVerifyDepth 1
 SSLOptions +StdEnvVars
 ErrorLog /dev/stderr
 CustomLog /dev/stdout request_summary
</VirtualHost>
''')
        mounts.append(str(apache)+':/etc/apache2/sites-enabled/000-default.conf:ro')
        configuration['services'][service_name] = {
            'image': 'vicartech-lot1-integration-runtime:20261117', 'pull_policy': 'never', 'user': '1000:1000',
            'environment': environment, 'volumes': mounts, 'networks': ['mesh', data_network],
            'depends_on': {'database-'+name: {'condition': 'service_healthy'}},
            'entrypoint': ['/bin/sh', '-c', 'mkdir -p storage/framework/sessions storage/framework/views storage/framework/cache storage/logs /tmp/apache2 /tmp/apache2-lock && php artisan migrate --force --no-interaction && exec apache2-foreground'],
            'tmpfs': ['/var/www/html/storage:mode=1777', '/var/www/html/bootstrap/cache:mode=1777', '/tmp:mode=1777'],
            'healthcheck': {'test': ['CMD', 'curl', '-fsS', 'http://127.0.0.1:8080/api/health'], 'interval': '3s', 'timeout': '3s', 'retries': 25},
            'security_opt': ['no-new-privileges:true'],
        }
    proxy_common = (BACKEND/'gateway/auth-proxy.conf').read_text()
    proxy_common = proxy_common.replace('/run/secrets/internal_ca_cert', '/run/integration/tls/ca.pem').replace('/run/secrets/gateway_client_cert', '/run/integration/tls/client/cert.pem').replace('/run/secrets/gateway_client_key', '/run/integration/tls/client/key.pem')
    proxy_common += '\nproxy_set_header X-Company-ID $http_x_company_id;\nproxy_set_header X-Request-ID $integration_request_id;\nproxy_set_header X-Roles "";\nproxy_set_header X-Permissions "";\n'
    gateway_config = '''map $request_id $integration_request_id {
 ~^(.{8})(.{4})(.{4})(.{4})(.{12})$ $1-$2-$3-$4-$5;
}
server {
 listen 8443 ssl;
 ssl_certificate /run/integration/tls/browser/cert.pem;
 ssl_certificate_key /run/integration/tls/browser/key.pem;
 ssl_protocols TLSv1.2 TLSv1.3;
 resolver 127.0.0.11 ipv6=off valid=1s;
 add_header Cache-Control "no-store" always;
 location = /gateway-health { return 200 'ok'; }
 location /api/internal/ { return 404; }
'''
    for path in ['/login', '/logout', '/sanctum/csrf-cookie', '/api/user', '/api/v2/me/companies']:
        gateway_config += ' location = '+path+' { include /etc/nginx/auth-proxy.conf; }\n'
    for name, paths in [('clients', ['clients', 'vehicles']), ('appointments', ['appointments', 'appointment-schedule'])]:
        gateway_config += ' location ~ ^/api/v1/(?:'+'|'.join(paths)+')(?:/|$) { include /etc/nginx/'+name+'-proxy.conf; }\n'
    for name in ['inventory', 'interventions', 'billing', 'accounting']:
        gateway_config += ' location /api/integration/'+name+'/ { rewrite ^/api/integration/'+name+'/(.*)$ /api/integration/v2/$1 break; include /etc/nginx/'+name+'-proxy.conf; }\n'
    gateway_config += ''' location /api/ { return 404; }
 location / { root /var/www/frontend; try_files $uri $uri/ /index.html; }
 error_page 502 504 =503 /integration-unavailable;
 location = /integration-unavailable { internal; default_type application/json; return 503 '{"error":{"code":"DEPENDENCY_UNAVAILABLE","requestId":"$integration_request_id"}}'; }
}
server { listen 8080; location = /gateway-health { return 200 'ok'; } location / { return 403; } }
'''
    # proxy_pass uses the current rewritten URI for diagnostics and the original query string.
    proxy_common = proxy_common.replace('$request_uri', '$uri$is_args$args')
    (workspace/'gateway.conf').write_text(gateway_config)
    (workspace/'nginx.conf').write_text((BACKEND/'gateway/nginx.conf').read_text().replace('$status $request_time', '$status $request_time $integration_request_id'))
    gateway_mounts = [str(workspace/'nginx.conf')+':/etc/nginx/nginx.conf:ro', str(workspace/'gateway.conf')+':/etc/nginx/conf.d/default.conf:ro', str(workspace/'tls/gateway')+':/run/integration/tls:ro', str(frontend.resolve())+':/var/www/frontend:ro']
    for name in ['auth', 'clients', 'appointments', 'inventory', 'interventions', 'billing', 'accounting']:
        proxy_path = workspace/(name+'-proxy.conf')
        proxy_path.write_text(proxy_common.replace('auth-service', name+'-service'))
        gateway_mounts.append(str(proxy_path)+':/etc/nginx/'+name+'-proxy.conf:ro')
    configuration['services']['gateway'] = {'image': 'vicartech-microservices-gateway:relationships-candidate', 'pull_policy': 'never', 'user': '1000:1000', 'volumes': gateway_mounts,
                                            'networks': ['mesh', 'frontend'], 'ports': ['127.0.0.1:5843:8443'],
                                            'healthcheck': {'test': ['CMD', 'wget', '-q', '-O', '/dev/null', 'http://127.0.0.1:8080/gateway-health'], 'interval': '3s', 'timeout': '3s', 'retries': 15},
                                            'security_opt': ['no-new-privileges:true']}
    (workspace/'compose.json').write_text(json.dumps(configuration, indent=2))
    public_key = certificate_tools.openssl('x509', '-in', workspace/'tls/gateway/browser/cert.pem', '-pubkey', '-noout')
    public_der = subprocess.run(['openssl', 'pkey', '-pubin', '-outform', 'DER'], input=public_key, capture_output=True, check=True).stdout
    (workspace/'browser-spki.txt').write_text(base64.b64encode(hashlib.sha256(public_der).digest()).decode())
    print(json.dumps({'project': project, 'workspace': str(workspace), 'https': 'https://localhost:5843', 'services': 7, 'databases': 7, 'sourceDataUsed': False}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace', type=Path, required=True)
    parser.add_argument('--frontend', type=Path, required=True)
    arguments = parser.parse_args()
    prepare(arguments.workspace.resolve(), arguments.frontend)
