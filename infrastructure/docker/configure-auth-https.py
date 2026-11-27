"""Configure local HTTPS and directory-mounted mTLS releases for an existing synthetic stack.

Requires an auth-e2e Compose JSON and a separately built validation frontend.
Never connects to source databases, creates users, or removes Docker data.
"""
import argparse
import base64
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess


def openssl(*arguments):
    return subprocess.run(['openssl', *map(str, arguments)], capture_output=True, check=True).stdout


def issue_certificate(authority, directory, identity, server_names=None, days=90):
    directory.mkdir(parents=True, exist_ok=False)
    os.chmod(directory, 0o700)
    openssl('req', '-new', '-newkey', 'rsa:2048', '-nodes', '-keyout', directory/'key.pem', '-out', directory/'request.pem', '-subj', '/CN='+identity)
    extensions = ['basicConstraints=critical,CA:FALSE', 'keyUsage=critical,digitalSignature,keyEncipherment', 'extendedKeyUsage='+('serverAuth' if server_names else 'clientAuth')]
    if server_names:
        extensions.append('subjectAltName='+server_names)
    (directory/'extensions.cnf').write_text('\n'.join(extensions)+'\n')
    openssl('x509', '-req', '-in', directory/'request.pem', '-CA', authority/'cert.pem', '-CAkey', authority/'key.pem', '-set_serial', '0x'+secrets.token_hex(16), '-days', days, '-extfile', directory/'extensions.cnf', '-out', directory/'cert.pem')
    shutil.copyfile(authority/'cert.pem', directory/'ca.pem')
    os.chmod(directory/'key.pem', 0o600)


def configure(compose_path, workspace, frontend):
    configuration = json.loads(compose_path.read_text())
    project = configuration['name']
    if not project.startswith('vicartech-auth-e2e-'):
        raise ValueError('Only a dedicated auth-e2e stack can be configured')
    workspace.mkdir(mode=0o700, parents=True, exist_ok=False)
    os.umask(0o077)
    authority = workspace/'authority'
    authority.mkdir()
    openssl('req', '-x509', '-newkey', 'rsa:3072', '-nodes', '-keyout', authority/'key.pem', '-out', authority/'cert.pem', '-days', 730, '-subj', '/CN=ViCarTech Local HTTPS Validation CA', '-addext', 'basicConstraints=critical,CA:TRUE', '-addext', 'keyUsage=critical,keyCertSign,cRLSign')
    tls_directory = workspace/'tls'
    for identity in ['gateway', 'auth-service', 'clients-service', 'appointments-service']:
        directory = tls_directory/identity
        (directory/'releases').mkdir(parents=True)
        issue_certificate(authority, directory/'releases/initial', identity, 'DNS:auth-service' if identity == 'auth-service' else None)
        (directory/'current').symlink_to('releases/initial')
        configuration['services'][identity]['volumes'].append(str(directory)+':/run/vicartech-tls:ro')
    issue_certificate(authority, tls_directory/'gateway/browser', 'localhost', 'DNS:localhost,IP:127.0.0.1')
    backend = Path(__file__).resolve().parents[2]
    proxy = (backend/'gateway/auth-proxy.conf').read_text()
    for old, new in [('internal_ca_cert','ca.pem'),('gateway_client_cert','cert.pem'),('gateway_client_key','key.pem')]:
        proxy = proxy.replace('/run/secrets/'+old, '/run/vicartech-tls/current/'+new)
    (workspace/'auth-proxy.conf').write_text(proxy)
    (workspace/'appointments-proxy.conf').write_text(proxy.replace('https://auth-service:8443','https://appointments-service:8443').replace('proxy_ssl_name auth-service;','proxy_ssl_name appointments-service;'))
    (workspace/'clients-proxy.conf').write_text(proxy.replace('https://auth-service:8443','https://clients-service:8443').replace('proxy_ssl_name auth-service;','proxy_ssl_name clients-service;'))
    issue_certificate(authority,tls_directory/'clients-service/server','clients-service','DNS:clients-service')
    issue_certificate(authority,tls_directory/'appointments-service/server','appointments-service','DNS:appointments-service')
    relationship_key = workspace/'appointments-relationship-key'
    relationship_key.write_text(secrets.token_hex(32))
    os.chmod(relationship_key,0o600)
    configuration['services']['clients-service']['volumes'].append(str(relationship_key)+':/run/secrets/appointments_relationship_key:ro')
    configuration['services']['clients-service']['environment']['APPOINTMENTS_RELATIONSHIP_KEY_FILE']='/run/secrets/appointments_relationship_key'
    configuration['services']['appointments-service']['volumes'].append(str(relationship_key)+':/run/secrets/clients_relationship_key:ro')
    configuration['services']['appointments-service']['environment'].update({'CLIENTS_RELATIONSHIP_KEY_FILE':'/run/secrets/clients_relationship_key','CLIENTS_SERVICE_URL':'https://clients-service:8443'})
    configuration['services']['appointments-service']['volumes'].append(str(backend/'services/appointments-service/docker/internal-apache.conf')+':/etc/apache2/sites-enabled/001-appointments-internal.conf:ro')
    configuration['services']['clients-service']['volumes'].append(str(backend/'services/clients-service/docker/internal-apache.conf')+':/etc/apache2/sites-enabled/001-clients-internal.conf:ro')
    gateway = (backend/'gateway/default.conf').read_text().replace('listen 8080;', '''listen 8443 ssl;
    ssl_certificate /run/vicartech-tls/browser/cert.pem;
    ssl_certificate_key /run/vicartech-tls/browser/key.pem;
    ssl_protocols TLSv1.2 TLSv1.3;''')
    gateway = gateway.replace('    location / {\n        return 503;\n    }', '''    location /api/ { return 503; }
    location / {
        root /var/www/frontend;
        try_files $uri $uri/ /index.html;
    }''')
    gateway += '''\nserver {
    listen 8080;
    location = /gateway-health { return 200 'ok'; }
    location / { return 308 https://localhost:5443$request_uri; }
}
'''
    (workspace/'gateway.conf').write_text(gateway)
    configuration['services']['gateway']['ports'] = ['127.0.0.1:5443:8443', '127.0.0.1:5191:8080']
    configuration['services']['gateway']['volumes'] += [str(workspace/'gateway.conf')+':/etc/nginx/conf.d/default.conf:ro', str(workspace/'auth-proxy.conf')+':/etc/nginx/auth-proxy.conf:ro',str(workspace/'clients-proxy.conf')+':/etc/nginx/clients-proxy.conf:ro',str(workspace/'appointments-proxy.conf')+':/etc/nginx/appointments-proxy.conf:ro', str(frontend.resolve())+':/var/www/frontend:ro']
    apache = (backend/'services/auth-service/docker/internal-apache.conf').read_text()
    for old, new in [('auth_server_cert','cert.pem'),('auth_server_key','key.pem'),('internal_ca_cert','ca.pem')]:
        apache = apache.replace('/run/secrets/'+old, '/run/vicartech-tls/current/'+new)
    (workspace/'internal-apache.conf').write_text(apache)
    configuration['services']['auth-service']['volumes'].append(str(workspace/'internal-apache.conf')+':/etc/apache2/sites-enabled/001-auth-internal.conf:ro')
    configuration['services']['auth-service']['environment'].update({'SESSION_SECURE_COOKIE':'true','APP_URL':'https://localhost:5443','FRONTEND_URL':'https://localhost:5443','SANCTUM_STATEFUL_DOMAINS':'localhost:5443'})
    for identity in ['clients-service', 'appointments-service']:
        configuration['services'][identity]['environment'].update({'AUTH_CA_CERTIFICATE_FILE':'/run/vicartech-tls/current/ca.pem','AUTH_CLIENT_CERTIFICATE_FILE':'/run/vicartech-tls/current/cert.pem','AUTH_CLIENT_KEY_FILE':'/run/vicartech-tls/current/key.pem'})
    # Remove unused TLS key mounts so each service sees only its own new identity.
    obsolete = {'/run/secrets/auth_server_cert','/run/secrets/auth_server_key','/run/secrets/internal_ca_cert','/run/secrets/gateway_client_cert','/run/secrets/gateway_client_key','/run/secrets/auth_ca_cert','/run/secrets/auth_client_cert','/run/secrets/auth_client_key'}
    for identity in ['gateway','auth-service','clients-service','appointments-service']:
        configuration['services'][identity]['volumes'] = [mount for mount in configuration['services'][identity]['volumes'] if mount.split(':')[1] not in obsolete]
    (workspace/'compose.json').write_text(json.dumps(configuration, indent=2))
    public_health = ['curl','--silent','--show-error','--fail-with-body','--cacert',str(authority/'cert.pem'),'https://localhost:5443/sanctum/csrf-cookie']
    health_commands = {identity:public_health for identity in ['gateway','auth-service']}
    for identity in ['clients-service','appointments-service']:
        health_commands[identity] = ['docker','exec',project+'-'+identity+'-1','curl','--fail','--silent','--show-error','--cacert','/run/vicartech-tls/current/ca.pem','--cert','/run/vicartech-tls/current/cert.pem','--key','/run/vicartech-tls/current/key.pem','https://auth-service:8443/api/health']
    (workspace/'rotation.json').write_text(json.dumps({'project':project,'tls_directory':str(tls_directory),'health_commands':health_commands}, indent=2))
    public_key = openssl('x509','-in',tls_directory/'gateway/browser/cert.pem','-pubkey','-noout')
    public_der = subprocess.run(['openssl','pkey','-pubin','-outform','DER'],input=public_key,capture_output=True,check=True).stdout
    (workspace/'browser-spki.txt').write_text(base64.b64encode(hashlib.sha256(public_der).digest()).decode())
    print(json.dumps({'project':project,'https':'https://localhost:5443','compose':str(workspace/'compose.json'),'authorityMountedInServices':False}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--compose', required=True, type=Path)
    parser.add_argument('--workspace', required=True, type=Path)
    parser.add_argument('--frontend', required=True, type=Path)
    arguments = parser.parse_args()
    configure(arguments.compose, arguments.workspace.resolve(), arguments.frontend)
