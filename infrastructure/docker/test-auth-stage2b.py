"""Exercise the isolated Stage 2B stack; never target the active gateway.

Required environment: AUTH_TEST_ACCOUNTS_FILE, AUTH_TEST_SECRETS_DIR.
The accounts JSON contains accounts with email/password. Secrets use Stage 2A names.
Credentials and cookies are never printed. Docker volumes and user records are preserved.
"""
import concurrent.futures
import hashlib
import hmac
import json
import os
from pathlib import Path
import secrets
import subprocess
import time
from urllib.parse import unquote

PROJECT = os.environ.get('AUTH_TEST_PROJECT', 'vicartech-microservices-stage1')
if PROJECT != 'vicartech-microservices-stage1' and not PROJECT.startswith('vicartech-auth-e2e-'):
    raise ValueError('Auth integration tests require the isolated Stage 2B stack or a dedicated auth-e2e project')
NETWORK = PROJECT + ('_stage1_data' if PROJECT == 'vicartech-microservices-stage1' else '_default')
PROBE = 'vicartech-stage2b-probe-' + secrets.token_hex(4)
PRIVATE = Path(os.environ['AUTH_TEST_SECRETS_DIR'])
ACCOUNTS = json.loads(Path(os.environ['AUTH_TEST_ACCOUNTS_FILE']).read_text())['accounts']


def docker(*arguments, body=None, check=True):
    return subprocess.run(['docker', *arguments], input=body, capture_output=True, check=check)


def container(service):
    name = PROJECT + '-' + service + '-1'
    metadata = json.loads(docker('inspect', name).stdout)[0]
    assert metadata['Config']['Labels']['com.docker.compose.project'] == PROJECT
    bindings = metadata['HostConfig']['PortBindings'] or {}
    if bindings:
        assert PROJECT.startswith('vicartech-auth-e2e-') and service == 'gateway'
        assert all(binding['HostIp'] == '127.0.0.1' for entries in bindings.values() for binding in entries), 'Only a loopback validation Gateway may publish ports'
    return name


def request(method, path, jar, service='gateway', body=None, csrf=None, headers=None):
    header_file = '/tmp/' + secrets.token_hex(8)
    command = ['exec', '-i', PROBE, 'curl', '--silent', '--show-error', '--max-time', '10',
               '--cookie', jar, '--cookie-jar', jar, '--dump-header', header_file,
               '--write-out', '\n%{http_code}', '--request', method,
               '--header', 'Host: localhost:5173', '--header', 'Origin: http://localhost:5173',
               '--header', 'Accept: application/json']
    if csrf:
        command += ['--header', 'X-XSRF-TOKEN: ' + csrf]
    for header in headers or []:
        command += ['--header', header]
    if body is not None:
        command += ['--header', 'Content-Type: application/json', '--data-binary', '@-']
    command += ['http://' + service + ':8080' + path]
    response = docker(*command, body=body).stdout
    content, status = response.rsplit(b'\n', 1)
    response_headers = docker('exec', PROBE, 'cat', header_file).stdout.decode()
    return int(status), content, response_headers


def cookie_entries(jar):
    entries = {}
    for line in docker('exec', PROBE, 'cat', jar).stdout.decode().splitlines():
        if line and (not line.startswith('#') or line.startswith('#HttpOnly_')):
            fields = line.split('\t')
            if len(fields) == 7:
                entries[fields[5]] = fields
    return entries


def csrf_token(jar):
    return unquote(cookie_entries(jar)['XSRF-TOKEN'][6])


def expect(status, result):
    assert result[0] == status, f'Expected HTTP {status}; received {result[0]}'
    return result


def wait_for_auth():
    for attempt in range(40):
        try:
            if request('GET', '/sanctum/csrf-cookie', '/tmp/readiness')[0] == 204:
                return
        except subprocess.CalledProcessError:
            pass
        time.sleep(0.5)
    raise AssertionError('Auth did not become ready')


def internal_request(jar, certificate=True, server_name='auth-service', wrong_ca=False, headers=None):
    target = '/api/internal/v1/session/validate?permission=clients.read'
    timestamp = str(int(time.time()))
    nonce = secrets.token_urlsafe(24)
    canonical = '\n'.join(['GET', target, timestamp, nonce, hashlib.sha256(b'').hexdigest()])
    signature = hmac.new((PRIVATE / 'clients-signing-key').read_bytes(), canonical.encode(), hashlib.sha256).hexdigest()
    command = ['exec', PROBE, 'curl', '--silent', '--show-error', '--max-time', '5',
               '--cacert', '/certs/client.crt' if wrong_ca else '/certs/ca.crt',
               '--header', 'Cookie: vicartech-auth-session=' + cookie_entries(jar)['vicartech-auth-session'][6],
               '--header', 'Accept: application/json', '--write-out', '\n%{http_code}']
    if certificate:
        command += ['--cert', '/certs/client.crt', '--key', '/certs/client.key']
    signed_headers = headers or ['X-Internal-Service: clients-service', 'X-Internal-Timestamp: ' + timestamp,
                                'X-Internal-Nonce: ' + nonce, 'X-Internal-Signature: ' + signature]
    for header in signed_headers:
        command += ['--header', header]
    command += ['https://' + server_name + ':8443' + target]
    result = docker(*command, check=False)
    return result, signed_headers


def run():
    auth_container = container('auth-service')
    business_services = ['clients-service', 'appointments-service']
    for service in ['gateway', *business_services]:
        container(service)
    command = ['run', '-d', '--name', PROBE, '--network', NETWORK, '--entrypoint', 'sleep']
    for source, destination in [('internal-ca.crt', 'ca.crt'), ('clients-client.crt', 'client.crt'), ('clients-client.key', 'client.key')]:
        command += ['--mount', f'type=bind,source={PRIVATE / source},target=/certs/{destination},readonly']
    docker(*command, 'vicartech-auth-service:stage2a', '1800')
    try:
        wait_for_auth()
        expect(401, request('GET', '/api/user', '/tmp/anonymous'))
        sessions = []
        for account_index, account in enumerate(ACCOUNTS):
            jar = '/tmp/account-' + str(account_index)
            expect(204, request('GET', '/sanctum/csrf-cookie', jar))
            entries = cookie_entries(jar)
            assert entries['vicartech-auth-session'][0] == '#HttpOnly_localhost'
            assert entries['vicartech-auth-session'][2] == '/'
            assert entries['XSRF-TOKEN'][0] == 'localhost'
            credentials = json.dumps({'email': account['email'], 'password': account['password']}).encode()
            expect(419, request('POST', '/login', jar, body=credentials))
            if account_index == 0:
                invalid_credentials = json.dumps({'email': account['email'], 'password': secrets.token_urlsafe(40)}).encode()
                expect(422, request('POST', '/login', jar, body=invalid_credentials, csrf=csrf_token(jar)))
            expect(204, request('POST', '/login', jar, body=credentials, csrf=csrf_token(jar)))
            response = expect(200, request('GET', '/api/user', jar))
            subject = str(json.loads(response[1])['id'])
            assert json.loads(response[1])['email'] == account['email']
            assert 'no-store' in response[2].lower()
            for service in business_services:
                result = expect(200, request('GET', '/api/internal/identity-check', jar, service,
                                             headers=['X-User-ID: 999']))
                assert json.loads(result[1])['subject'] == subject
                assert result[2].lower().count('set-cookie:') == 2
                expect(419, request('POST', '/api/internal/identity-check', jar, service, body=b'{}'))
                expect(200, request('POST', '/api/internal/identity-check', jar, service,
                                    body=b'{}', csrf=csrf_token(jar)))
            sessions.append((jar, subject))
            print(f'Account {account_index + 1}: login, refresh, cookies, remote identity and write CSRF passed')
        assert len(sessions) == 2 and sessions[0][1] != sessions[1][1]
        jar = sessions[0][0]
        result, signed_headers = internal_request(jar)
        assert result.returncode == 0 and result.stdout.endswith(b'\n200')
        replay, _ = internal_request(jar, headers=signed_headers)
        assert replay.stdout.endswith(b'\n401')
        assert internal_request(jar, certificate=False)[0].returncode != 0
        assert internal_request(jar, server_name=auth_container)[0].returncode != 0
        assert internal_request(jar, wrong_ca=True)[0].returncode != 0
        print('mTLS: valid caller accepted; absent certificate, wrong server name, wrong CA and replay rejected')
        expect(404, request('GET', '/api/internal/v1/session/validate', jar))
        docker('restart', container('database'))
        docker('restart', auth_container, container('gateway'), *[container(service) for service in business_services])
        wait_for_auth()
        for session_jar, subject in sessions:
            response = expect(200, request('GET', '/api/user', session_jar))
            assert str(json.loads(response[1])['id']) == subject
        print('Both sessions survived database, Auth, Gateway and business container restarts without signing in again')
        docker('pause', auth_container)
        try:
            expect(503, request('GET', '/api/user', jar))
            for service in business_services:
                expect(503, request('GET', '/api/internal/identity-check', jar, service))
        finally:
            docker('unpause', auth_container)
        print('Auth timeout: Gateway and both protected adapters returned 503')
        docker('stop', '--time', '5', auth_container)
        try:
            expect(503, request('GET', '/api/user', jar))
            for service in business_services:
                expect(503, request('GET', '/api/internal/identity-check', jar, service))
        finally:
            docker('start', auth_container)
            wait_for_auth()
        print('Auth stopped/refused connection: Gateway and protected adapters returned 503')
        for session_jar, subject in sessions:
            old_cookie = cookie_entries(session_jar)['vicartech-auth-session'][6]
            with concurrent.futures.ThreadPoolExecutor() as executor:
                read = executor.submit(request, 'GET', '/api/user', session_jar + '-parallel',
                                       headers=['Cookie: vicartech-auth-session=' + old_cookie])
                expect(204, request('POST', '/logout', session_jar, csrf=csrf_token(session_jar)))
                assert read.result()[0] in [200, 401]
            expect(401, request('GET', '/api/user', session_jar))
            for service in business_services:
                expect(401, request('GET', '/api/internal/identity-check', session_jar, service,
                                    headers=['Cookie: vicartech-auth-session=' + old_cookie]))
        print('Logout and concurrent read: previous sessions cannot authorize another request')
    finally:
        docker('rm', '-f', PROBE, check=False)


if __name__ == '__main__':
    run()
