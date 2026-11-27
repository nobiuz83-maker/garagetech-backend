"""Validate and activate an issued mTLS leaf release in a dedicated validation stack.

The signing authority stays external. Each service mounts its own directory read-only.
Old releases are retained. No container restart or production activation is performed.
"""
import argparse
import fcntl
import json
import os
import re
import time
from pathlib import Path
import secrets
import shutil
import subprocess


def command(arguments):
    result = subprocess.run(arguments, capture_output=True, check=False, timeout=20)
    if result.returncode:
        raise RuntimeError('Certificate validation or service control failed: '+arguments[0])
    return result.stdout


def validate_release(current, candidate, identity, minimum_validity):
    purpose = 'sslserver' if identity == 'auth-service' else 'sslclient'
    command(['openssl', 'verify', '-CAfile', str(current/'ca.pem'), '-purpose', purpose, str(candidate/'cert.pem')])
    command(['openssl', 'x509', '-in', str(candidate/'cert.pem'), '-checkend', str(minimum_validity), '-noout'])
    command(['openssl', 'verify', '-attime', str(int(time.time())+minimum_validity), '-CAfile', str(current/'ca.pem'), '-purpose', purpose, str(candidate/'cert.pem')])
    subject = command(['openssl', 'x509', '-in', str(candidate/'cert.pem'), '-noout', '-subject', '-nameopt', 'RFC2253']).decode().strip()
    if subject != 'subject=CN='+identity:
        raise ValueError('The certificate subject must match the service identity exactly')
    if identity == 'auth-service':
        command(['openssl', 'verify', '-CAfile', str(current/'ca.pem'), '-verify_hostname', identity, str(candidate/'cert.pem')])
    certificate_key = command(['openssl', 'x509', '-in', str(candidate/'cert.pem'), '-noout', '-pubkey'])
    private_key = command(['openssl', 'pkey', '-in', str(candidate/'key.pem'), '-pubout'])
    if certificate_key != private_key:
        raise ValueError('The certificate and private key do not match')
    if (candidate/'ca.pem').read_bytes() != (current/'ca.pem').read_bytes():
        raise ValueError('Leaf renewal must preserve the approved trust bundle; CA rollover is a separate operation')


def activate_link(root, release):
    temporary = root/('.next-'+secrets.token_hex(8))
    temporary.symlink_to(release.relative_to(root))
    os.replace(temporary, root/'current')


def reload_service(container, identity):
    if identity == 'gateway':
        command(['docker', 'exec', container, 'nginx', '-t'])
        command(['docker', 'exec', container, 'nginx', '-s', 'reload'])
    elif identity == 'auth-service':
        command(['docker', 'exec', container, 'sh', '-c', '. /etc/apache2/envvars; exec apache2 -t'])
        command(['docker', 'exec', container, 'sh', '-c', '. /etc/apache2/envvars; exec apache2 -k graceful'])
    # PHP adapters create their TLS HTTP client per verification, reading the current paths.


def rotate(manifest, identity, release_name):
    project = manifest['project']
    if not project.startswith('vicartech-auth-e2e-'):
        raise ValueError('Live activation is restricted to a dedicated auth-e2e validation project')
    root = Path(manifest['tls_directory']).resolve()/identity
    candidate = (root/'releases'/release_name).resolve()
    if candidate.parent != root/'releases' or not candidate.is_dir():
        raise ValueError('The candidate must be a prepared release within this service directory')
    container = project+'-'+identity+'-1'
    metadata = json.loads(command(['docker', 'inspect', container]))[0]
    if metadata['Config']['Labels'].get('com.docker.compose.project') != project:
        raise ValueError('The target container does not belong to the validation project')
    with (root/'.rotation.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        previous = (root/'current').resolve()
        validate_release(previous, candidate, identity, manifest.get('minimum_validity_seconds', 604800))
        activate_link(root, candidate)
        try:
            reload_service(container, identity)
            health = manifest['health_commands'][identity]
            command(health)
        except Exception:
            activate_link(root, previous)
            reload_service(container, identity)
            command(manifest['health_commands'][identity])
            raise RuntimeError('Candidate activation failed; the previous release was restored') from None
        return {'identity': identity, 'previousRelease': previous.name, 'activeRelease': candidate.name, 'containerRestarted': False}


def prepare_release(manifest, identity, release_name):
    root = Path(manifest['tls_directory']).resolve()/identity
    candidate = root/'releases'/release_name
    if candidate.resolve().parent != root/'releases':
        raise ValueError('The release must be directly within the service release directory')
    candidate.mkdir(mode=0o700, exist_ok=False)
    purpose = 'serverAuth' if identity == 'auth-service' else 'clientAuth'
    arguments = ['openssl','req','-new','-newkey','rsa:3072','-nodes','-keyout',str(candidate/'key.pem'),'-out',str(candidate/'request.pem'),'-subj','/CN='+identity,
                 '-addext','basicConstraints=critical,CA:FALSE','-addext','keyUsage=critical,digitalSignature,keyEncipherment','-addext','extendedKeyUsage='+purpose]
    if identity == 'auth-service':
        arguments += ['-addext','subjectAltName=DNS:auth-service']
    command(arguments)
    os.chmod(candidate/'key.pem',0o600)
    shutil.copyfile(root/'current/ca.pem',candidate/'ca.pem')
    return {'identity':identity,'release':release_name,'certificateSigningRequest':str(candidate/'request.pem'),'activated':False}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--manifest', required=True, type=Path)
    parser.add_argument('--identity', required=True, choices=['gateway', 'auth-service', 'clients-service', 'appointments-service'])
    action = parser.add_mutually_exclusive_group(required=True)
    action.add_argument('--release')
    action.add_argument('--prepare-release')
    action.add_argument('--check-expiry', action='store_true')
    parser.add_argument('--renew-before-days', type=int, default=30)
    arguments = parser.parse_args()
    manifest = json.loads(arguments.manifest.read_text())
    if arguments.prepare_release:
        print(json.dumps(prepare_release(manifest,arguments.identity,arguments.prepare_release)))
        return
    if arguments.renew_before_days < 1:
        parser.error('--renew-before-days must be positive')
    if arguments.check_expiry:
        certificate = Path(manifest['tls_directory'])/arguments.identity/'current/cert.pem'
        seconds = str(arguments.renew_before_days*86400)
        result = subprocess.run(['openssl', 'x509', '-in', str(certificate), '-checkend', seconds, '-noout'], capture_output=True)
        certificates = re.findall(b'-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----', certificate.with_name('ca.pem').read_bytes(), re.DOTALL)
        authority_due = not certificates or any(subprocess.run(['openssl','x509','-checkend',seconds,'-noout'],input=trusted,capture_output=True).returncode != 0 for trusted in certificates)
        due = result.returncode != 0 or authority_due
        print(json.dumps({'identity': arguments.identity, 'renewalRequired': due, 'authorityRenewalRequired': authority_due}))
        raise SystemExit(2 if due else 0)
    if not arguments.release:
        parser.error('--release is required for activation')
    print(json.dumps(rotate(manifest, arguments.identity, arguments.release)))


if __name__ == '__main__':
    main()
