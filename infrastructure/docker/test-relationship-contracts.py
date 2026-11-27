"""End-to-end relationship and archive tests on a dedicated synthetic HTTPS stack."""
import argparse
from datetime import date, timedelta
from concurrent.futures import ThreadPoolExecutor
from http.cookiejar import CookieJar
import json
from pathlib import Path
import secrets
import ssl
import subprocess
import urllib.error
import urllib.parse
import urllib.request


def run(workspace, accounts_path, output):
    project='vicartech-auth-e2e-validation'
    def docker(*arguments,check=True):
        return subprocess.run(['docker',*arguments],capture_output=True,check=check,timeout=40)
    for service in ['clients-service','appointments-service','auth-service','database','gateway']:
        metadata=json.loads(docker('inspect',project+'-'+service+'-1').stdout)[0]
        assert metadata['Config']['Labels']['com.docker.compose.project']==project
    accounts=json.loads(accounts_path.read_text())['accounts']
    context=ssl.create_default_context(cafile=str(workspace/'authority/cert.pem'))
    sessions=[];checks=[]
    def passed(name):checks.append({'name':name,'status':'passed'})
    def call(session,path,method='GET',body=None,csrf=True):
        client,jar=session
        headers={'Accept':'application/json','Origin':'https://localhost:5443'}
        if body is not None:headers['Content-Type']='application/json'
        if csrf and method not in ['GET','HEAD']:
            token=next((cookie.value for cookie in jar if cookie.name=='XSRF-TOKEN'),None)
            if token:headers['X-XSRF-TOKEN']=urllib.parse.unquote(token)
        message=urllib.request.Request('https://localhost:5443'+path,data=None if body is None else json.dumps(body).encode(),headers=headers,method=method)
        try:response=client.open(message,timeout=12)
        except urllib.error.HTTPError as response:
            content=response.read();return response.code,json.loads(content) if content.startswith(b'{') else None
        with response:
            content=response.read();return response.status,json.loads(content) if content else None
    for account in accounts:
        jar=CookieJar();client=urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),urllib.request.HTTPCookieProcessor(jar));session=(client,jar)
        assert call(session,'/sanctum/csrf-cookie')[0]==204
        assert call(session,'/login','POST',{'email':account['email'],'password':account['password']})[0]==204
        assert call(session,'/api/v1/appointments')[0]==200
        assert call(session,'/api/v1/appointment-schedule')==(200,{'data':None})
        sessions.append(session)
    first,second=sessions
    booking_day=date(2030,1,7)+timedelta(weeks=secrets.randbelow(100000))
    def create_client():
        suffix=secrets.token_hex(6)
        body={'firstName':'Relationship-'+suffix,'lastName':'Fictional','phone':'','email':'','vehicles':[
            {'make':'Test','model':model,'year':2020,'vin':'','licensePlate':suffix+model} for model in ['First','Second']]}
        status,response=call(first,'/api/v1/clients','POST',body);assert status==201
        status,resource=call(first,'/api/v1/clients/'+response['data']['id']);assert status==200
        return resource['data']
    def booking(client,date=None):
        date=date or booking_day.isoformat()
        return {'clientId':client['id'],'vehicleId':client['vehicles'][0]['id'],'date':date,'startTime':'10:00','endTime':'11:00','status':'scheduled','serviceType':'Test','description':'Fictional relationship','notes':'','address':''}
    client=create_client()
    status,created=call(first,'/api/v1/appointments','POST',booking(client));assert status==201,(status,created)
    identifier=created['data']['id']
    passed('Gateway reaches Appointments; creation retains the Clients reference and commits a local SQL association')
    assert call(second,'/api/v1/appointments/'+identifier)[0]==403
    assert call(second,'/api/v1/appointments','POST',booking(client))[0]==422
    assert call(first,'/api/v1/appointments','POST',booking(client),csrf=False)[0]==419
    assert call(first,'/api/internal/v1/appointment-references')[0]==404
    passed('Foreign ownership, missing CSRF and public internal endpoints are refused')
    assert call(first,'/api/v1/clients/'+client['id'],'DELETE')[0]==422
    assert call(first,'/api/v1/appointments/'+identifier+'/cancel','POST',{'version':1})[0]==200
    assert call(first,'/api/v1/appointments/'+identifier,'DELETE',{'version':2})[0]==204
    assert call(first,'/api/v1/clients/'+client['id'],'DELETE')[0]==422
    passed('Cancelled and archived appointments retain their reference; historical clients cannot be archived')
    # Use a different booking date so overlap checks do not obscure name search.
    status,created=call(first,'/api/v1/appointments','POST',booking(client,(booking_day+timedelta(days=7)).isoformat()));assert status==201
    status,search=call(first,'/api/v1/appointments?search='+urllib.parse.quote(client['firstName']));assert status==200
    assert any(record['id']==created['data']['id'] for record in search['data'])
    passed('Appointment name search resolves IDs through the Clients API rather than SQL joins')
    restorable=create_client();first_vehicle=restorable['vehicles'][0]['id'];second_vehicle=restorable['vehicles'][1]['id']
    assert call(first,'/api/v1/vehicles/'+first_vehicle,'DELETE')[0]==204
    assert call(first,'/api/v1/clients/'+restorable['id'],'DELETE')[0]==204
    assert call(second,'/api/v1/clients/'+restorable['id']+'/restore','POST')[0]==403
    assert call(first,'/api/v1/vehicles/'+first_vehicle+'/restore','POST')[0]==409
    status,restored=call(first,'/api/v1/clients/'+restorable['id']+'/restore','POST');assert status==200
    assert [vehicle['id'] for vehicle in restored['data']['vehicles']]==[second_vehicle]
    assert call(first,'/api/v1/vehicles/'+first_vehicle+'/restore','POST')[0]==200
    assert call(first,'/api/v1/clients/'+restorable['id']+'/restore','POST')[0]==200
    passed('Cascade restoration preserves separately archived vehicles; owner and parent rules are enforced')
    # Sessions are independent per thread. No cookie jar is shared while the two operations race.
    for iteration in range(3):
        racing=create_client();payload=booking(racing,(booking_day+timedelta(days=14+7*iteration)).isoformat())
        def race(path,method,body):
            jar=CookieJar()
            for cookie in first[1]:jar.set_cookie(cookie)
            client=urllib.request.build_opener(urllib.request.HTTPSHandler(context=context),urllib.request.HTTPCookieProcessor(jar))
            return call((client,jar),path,method,body)[0]
        with ThreadPoolExecutor(max_workers=2) as executor:
            archive=executor.submit(race,'/api/v1/clients/'+racing['id'],'DELETE',None)
            appointment=executor.submit(race,'/api/v1/appointments','POST',payload)
            outcomes=(archive.result(),appointment.result())
        assert outcomes in [(204,422),(422,201)],outcomes
    passed('Three concurrent archive/appointment races admit exactly one side, with no dangling active appointment')
    failing=create_client()
    docker('pause',project+'-clients-service-1')
    try:assert call(first,'/api/v1/appointments','POST',booking(failing,(booking_day+timedelta(days=49)).isoformat()))[0]==503
    finally:docker('unpause',project+'-clients-service-1')
    docker('pause',project+'-auth-service-1')
    try:
        assert call(first,'/api/v1/appointments')[0]==503
        assert call(first,'/api/v1/clients')[0]==503
    finally:docker('unpause',project+'-auth-service-1')
    passed('Clients or Auth outages refuse protected operations with 503 and no local authorization fallback')
    assert call(first,'/logout','POST')[0]==204
    assert call(first,'/api/v1/appointments')[0]==401
    assert call(second,'/api/v1/appointments')[0]==200
    passed('Logout invalidates both domains without affecting the other user session')
    output.write_text(json.dumps({'checks':checks,'passed':len(checks),'failed':0,'syntheticRecordsRetained':True},indent=2))
    print(json.dumps({'passed':len(checks),'failed':0}))


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace',required=True,type=Path);parser.add_argument('--accounts',required=True,type=Path);parser.add_argument('--output',required=True,type=Path)
    arguments=parser.parse_args();run(arguments.workspace,arguments.accounts,arguments.output)
