"""Validate the Clients candidate through the isolated HTTPS Gateway, using fictional accounts only."""
import argparse
from http.cookiejar import CookieJar
import json
from pathlib import Path
import secrets
import ssl
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request


def docker(*arguments, body=None, check=True):
    return subprocess.run(['docker', *arguments], input=body, capture_output=True, check=check, timeout=40)


def run(workspace, accounts_path, output):
    manifest = json.loads((workspace/'rotation.json').read_text())
    project = manifest['project']
    if not project.startswith('vicartech-auth-e2e-'):
        raise ValueError('A dedicated synthetic auth-e2e stack is required')
    containers = {service:project+'-'+service+'-1' for service in ['database','auth-service','clients-service','appointments-service','gateway']}
    for name in containers.values():
        metadata = json.loads(docker('inspect',name).stdout)[0]
        assert metadata['Config']['Labels']['com.docker.compose.project'] == project
        assert all(mount['Type'] != 'volume' or mount['Name'].startswith(project+'_') for mount in metadata['Mounts'])
    accounts = json.loads(accounts_path.read_text())['accounts']
    checks = []
    def passed(name): checks.append({'name':name,'status':'passed'})
    sessions = []
    context = ssl.create_default_context(cafile=str(workspace/'authority/cert.pem'))
    def request(session, path, method='GET', payload=None, csrf=True, headers=None):
        client, jar = session
        request_headers = {'Accept':'application/json','Origin':'https://localhost:5443',**(headers or {})}
        if csrf and method not in ['GET','HEAD']:
            token = next((cookie.value for cookie in jar if cookie.name == 'XSRF-TOKEN'),None)
            if token: request_headers['X-XSRF-TOKEN'] = urllib.parse.unquote(token)
        body = None
        if payload is not None:
            body = json.dumps(payload).encode()
            request_headers['Content-Type'] = 'application/json'
        message = urllib.request.Request('https://localhost:5443'+path,data=body,method=method,headers=request_headers)
        try: response = client.open(message,timeout=10)
        except urllib.error.HTTPError as response:
            return response.code,json.loads(response.read() or b'null'),response.headers
        with response:
            body = response.read()
            return response.status,json.loads(body) if body else None,response.headers
    for account in accounts:
        jar = CookieJar()
        client = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),urllib.request.HTTPCookieProcessor(jar))
        session = (client,jar)
        assert request(session,'/api/v1/clients')[0] == 401
        assert request(session,'/sanctum/csrf-cookie')[0] == 204
        assert request(session,'/login','POST',{'email':account['email'],'password':account['password']})[0] == 204
        assert request(session,'/api/v1/clients')[0] == 200
        sessions.append(session)
    passed('Both fictional users authenticate and receive the real paginated Clients API instead of 503')
    first, second = sessions
    suffix = secrets.token_hex(6)
    payload = {'firstName':'Gateway-'+suffix,'lastName':'Fictional','phone':'','email':'','data':{'schemaVersion':1,'preferences':{'language':'fr'}},'vehicles':[
        {'make':'Toyota','model':'Corolla','year':2020,'vin':'','licensePlate':suffix,'data':{'schemaVersion':1,'engine':{'fuelType':'gasoline'}}},
        {'make':'Honda','model':'Civic','year':2021,'vin':'','licensePlate':suffix+'-2'},
    ],'user_id':'999'}
    assert request(first,'/api/v1/clients','POST',payload,csrf=False)[0] == 419
    assert request(first,'/api/v1/clients','POST',payload,csrf=False,headers={'X-XSRF-TOKEN':'invalid'})[0] == 419
    status, created, _ = request(first,'/api/v1/clients','POST',payload)
    assert status == 201
    client_id = created['data']['id']
    status, record, headers = request(first,'/api/v1/clients/'+client_id)
    assert status == 200 and len(record['data']['vehicles']) == 2
    assert 'no-store' in headers.get('Cache-Control','')
    assert len(headers.get_all('Set-Cookie')) == 2
    assert all(cookie.secure for cookie in first[1] if cookie.name in ['vicartech-auth-session','XSRF-TOKEN'])
    passed('Creation uses remote write CSRF, preserves SQL/JSON relations and relays both Secure Auth cookies')
    vehicle_id = record['data']['vehicles'][0]['id']
    assert request(second,'/api/v1/clients/'+client_id)[0] == 403
    assert request(second,'/api/v1/clients/'+client_id,'PATCH',{'firstName':'Forbidden'})[0] == 403
    assert request(second,'/api/v1/clients/'+client_id,'DELETE')[0] == 403
    assert request(second,'/api/v1/vehicles/'+vehicle_id)[0] == 403
    status, listing, _ = request(second,'/api/v1/clients',headers={'X-User-ID':'1'})
    assert status == 200 and all(client['id'] != client_id for client in listing['data'])
    passed('Actual Laravel Policies deny foreign resources and forged ownership headers')
    assert request(first,'/api/v1/clients/'+client_id,'PATCH',{'firstName':'Updated-'+suffix,'data':{'schemaVersion':1,'additionalInformation':{'notes':'Fictional validation'}}})[0] == 200
    status, record, _ = request(first,'/api/v1/clients/'+client_id)
    assert status == 200 and record['data']['data']['preferences']['language'] == 'fr'
    assert len(record['data']['vehicles']) == 2
    passed('Nested JSON updates preserve previous optional properties and vehicle relationships')
    assert request(first,'/api/v1/clients/'+client_id,'DELETE')[0] == 503
    assert request(first,'/api/v1/clients/'+client_id)[0] == 200
    passed('Unimplemented external-history verification cannot permit destructive operations')
    # Public business traffic must use mTLS even if a caller already has an opaque cookie.
    transport = docker('exec',containers['clients-service'],'curl','--silent','--show-error','--output','/dev/null','--write-out','%{http_code}','http://127.0.0.1:8080/api/v1/clients')
    assert transport.stdout == b'403'
    wrong_caller = docker('exec',containers['clients-service'],'curl','--silent','--show-error','--cacert','/run/vicartech-tls/current/ca.pem','--cert','/run/vicartech-tls/current/cert.pem','--key','/run/vicartech-tls/current/key.pem','--output','/dev/null','--write-out','%{http_code}','https://clients-service:8443/api/v1/clients')
    assert wrong_caller.stdout == b'403'
    missing_certificate = docker('exec',containers['clients-service'],'curl','--silent','--show-error','--cacert','/run/vicartech-tls/current/ca.pem','--output','/dev/null','--write-out','%{http_code}','https://clients-service:8443/api/v1/clients',check=False)
    assert missing_certificate.returncode != 0 or missing_certificate.stdout != b'200'
    passed('Gateway-to-Clients mTLS works; HTTP business traffic, absent certificates and non-Gateway callers are refused')
    docker('pause',containers['auth-service'])
    try:
        assert request(first,'/api/v1/clients')[0] == 503
        assert request(first,'/api/v1/clients','POST',payload)[0] == 503
        assert request(first,'/api/user')[0] == 503
    finally: docker('unpause',containers['auth-service'])
    assert request(first,'/api/v1/clients/'+client_id)[0] == 200
    passed('Auth outage fails closed with 503 on actual business reads and writes, without local authorization fallback')
    docker('pause',containers['clients-service'])
    try:
        assert request(first,'/api/v1/clients')[0] == 503
        assert request(first,'/logout','POST')[0] == 204
        assert request(first,'/api/user')[0] == 401
    finally: docker('unpause',containers['clients-service'])
    assert request(first,'/api/v1/clients')[0] == 401
    assert request(second,'/api/v1/clients')[0] == 200
    passed('Logout remains independent of Clients availability and invalidates business access without ending the other user session')
    for name in containers.values(): docker('restart',name)
    for attempt in range(40):
        try:
            if request(second,'/api/v1/clients')[0] == 200: break
        except (urllib.error.URLError,TimeoutError): pass
        time.sleep(.25)
    else: raise AssertionError('The session did not recover after container restarts')
    assert request(second,'/logout','POST')[0] == 204
    assert request(second,'/api/v1/clients')[0] == 401
    passed('HTTPS session and business authorization survive isolated container restarts; logout then revokes access')
    report={'project':project,'checks':checks,'passed':len(checks),'failed':0,'fictionalRecordsRetained':True}
    output.write_text(json.dumps(report,indent=2))
    print(json.dumps({'passed':len(checks),'failed':0}))


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace',required=True,type=Path)
    parser.add_argument('--accounts',required=True,type=Path)
    parser.add_argument('--output',required=True,type=Path)
    arguments=parser.parse_args()
    run(arguments.workspace,arguments.accounts,arguments.output)
