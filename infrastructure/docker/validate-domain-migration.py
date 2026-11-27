"""Validate and compare explicit logical snapshots without database access or data writes.

Users, credentials and sessions are deliberately outside this format. Unknown SQL columns
block migration; unknown JSON properties are preserved and compared, never silently removed.
"""
import argparse
import copy
from datetime import date, datetime
import hashlib
import json
from pathlib import Path
import re
from uuid import UUID

TABLE_COLUMNS = {
    'clients': {'id','user_id','first_name','last_name','phone','email','address','data','created_at','updated_at','deleted_at'},
    'vehicles': {'id','client_id','make','model','year','vin','license_plate','data','created_at','updated_at','deleted_at'},
    'appointments': {'id','user_id','client_id','vehicle_id','date','start_time','end_time','status','service_type','description','notes','data','version','created_at','updated_at','deleted_at'},
    'appointment_schedules': {'id','user_id','data','created_at','updated_at'},
}


def read_snapshot(path):
    def unique_properties(pairs):
        document = {}
        for property_name, value in pairs:
            if property_name in document:
                raise ValueError('Duplicated JSON properties are ambiguous and require explicit review')
            document[property_name] = value
        return document
    return json.loads(path.read_text(), object_pairs_hook=unique_properties)


def identifier(value):
    if not isinstance(value,str): raise ValueError('Domain identifiers must be UUID strings')
    try:
        canonical = str(UUID(value))
        if canonical != value: raise ValueError('UUIDs must already be canonical')
        return canonical
    except ValueError: raise ValueError('Malformed domain UUID') from None


def subject(value):
    if not isinstance(value,str) or not re.fullmatch(r'[1-9][0-9]{0,19}',value) or int(value)>18446744073709551615:
        raise ValueError('Subject identifiers must be canonical unsigned BIGINT strings')
    return value


def validate_json_numbers(value):
    if type(value) is int and not -9223372036854775808 <= value <= 9223372036854775807:
        raise ValueError('JSON integer exceeds the lossless PHP runtime range; explicit migration required')
    if isinstance(value, dict):
        for property_value in value.values():
            validate_json_numbers(property_value)
    elif isinstance(value, list):
        for property_value in value:
            validate_json_numbers(property_value)


def validate(snapshot):
    if set(snapshot) != {'schemaVersion','ownerSubjects','tables'} or type(snapshot['schemaVersion']) is not int or snapshot['schemaVersion'] != 1:
        raise ValueError('Unsupported or ambiguous snapshot format')
    owners = snapshot['ownerSubjects']
    if not isinstance(owners,list) or len(set(map(subject,owners))) != len(owners):
        raise ValueError('Invalid or duplicated owner projection')
    tables = snapshot['tables']
    if set(tables) != set(TABLE_COLUMNS): raise ValueError('Snapshot must contain only the four declared business tables')
    indexed = {}
    for table,records in tables.items():
        if not isinstance(records,list): raise ValueError('Snapshot tables must be ordered record arrays')
        indexed[table] = {}
        for record in records:
            if not isinstance(record,dict) or set(record)-TABLE_COLUMNS[table]: raise ValueError('Unknown SQL columns require an explicit migration mapping')
            required = TABLE_COLUMNS[table]-{'address'}
            if not required.issubset(record): raise ValueError('Missing SQL columns; values must not be invented')
            key = identifier(record['id']) if table != 'appointment_schedules' else subject(record['id'])
            if key in indexed[table]: raise ValueError('Duplicate canonical identifier')
            indexed[table][key] = record
            if 'user_id' in record and subject(record['user_id']) not in owners: raise ValueError('Unverified owner projection')
            for field in ['created_at','updated_at','deleted_at']:
                value = record.get(field)
                if field == 'deleted_at' and value is None: continue
                if field == 'deleted_at' and table == 'appointment_schedules': continue
                if not isinstance(value,str): raise ValueError('Missing or invalid historical timestamp')
                try: datetime.fromisoformat(value.replace('Z','+00:00'))
                except ValueError: raise ValueError('Invalid historical timestamp') from None
            document = record['data']
            if document is not None and (not isinstance(document,dict) or (type(document.get('schemaVersion')) is not int or document.get('schemaVersion') != 1)):
                raise ValueError('Unknown document version requires an explicit tested transformer')
            validate_json_numbers(record)
            json.dumps(record,sort_keys=True,allow_nan=False)
    for vehicle in indexed['vehicles'].values():
        if type(vehicle['year']) is not int or not 0 <= vehicle['year'] <= 65535: raise ValueError('Invalid SQL vehicle year')
        if identifier(vehicle['client_id']) not in indexed['clients']: raise ValueError('Orphan vehicle')
    for appointment in indexed['appointments'].values():
        client = indexed['clients'].get(identifier(appointment['client_id']))
        vehicle = indexed['vehicles'].get(identifier(appointment['vehicle_id']))
        if client is None or vehicle is None or identifier(vehicle['client_id']) != identifier(client['id']): raise ValueError('Invalid appointment client/vehicle association')
        if appointment['user_id'] != client['user_id']: raise ValueError('Cross-owner appointment reference')
        if type(appointment['version']) is not int or not 1 <= appointment['version'] <= 4294967295: raise ValueError('Invalid optimistic version')
        if not isinstance(appointment['date'],str) or date.fromisoformat(appointment['date']).isoformat() != appointment['date']: raise ValueError('Invalid appointment date')
        for field in ['start_time','end_time']:
            if not isinstance(appointment[field],str) or not re.fullmatch(r'(?:[01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]',appointment[field]): raise ValueError('Invalid appointment time')
        if appointment['start_time'] >= appointment['end_time']: raise ValueError('Invalid appointment interval')
        if appointment['data'] is None: raise ValueError('Appointment JSON cannot be null')
        if appointment['status'] not in ['scheduled','confirmed','inProgress','completed','cancelled']: raise ValueError('Unknown appointment status')
    schedules = [record['user_id'] for record in indexed['appointment_schedules'].values()]
    if len(set(schedules)) != len(schedules): raise ValueError('Duplicate owner schedule')
    return indexed


def transform_source(snapshot):
    validate(snapshot)
    transformed = copy.deepcopy(snapshot)
    for client in transformed['tables']['clients']:
        address = client.pop('address', None)
        document = client['data']
        if address is not None:
            if document is None:
                document = {'schemaVersion': 1}
            if 'address' in document and document['address'] != address:
                raise ValueError('Conflicting SQL and JSON addresses require an explicit lossless mapping')
            document['address'] = address
        client['data'] = document
    return transformed


def compare(source, target):
    expected = transform_source(source)
    target = copy.deepcopy(target)
    validate(target)
    if any('address' in record for record in target['tables']['clients']): raise ValueError('Target must not duplicate the legacy SQL address column')
    for snapshot in [expected,target]:
        snapshot['ownerSubjects'].sort()
        for table in TABLE_COLUMNS: snapshot['tables'][table].sort(key=lambda record: str(record['id']))
    canonical = lambda snapshot: json.dumps(snapshot,sort_keys=True,separators=(',',':'),ensure_ascii=False,allow_nan=False).encode()
    if canonical(expected) != canonical(target): raise ValueError('Target differs from the approved source transformation; migration remains blocked')
    references = {(record['id'],record['client_id'],record['vehicle_id'],record['user_id']) for record in source['tables']['appointments']}
    return {'valid':True,'rowCounts':{table:len(records) for table,records in target['tables'].items()},'historicalReferencesRequired':len(references),
            'unknownArchiveProvenance':{table:sum(record['deleted_at'] is not None for record in source['tables'][table]) for table in ['clients','vehicles']},
            'logicalTargetSha256':hashlib.sha256(canonical(target)).hexdigest(),'databaseWritesPerformed':False}


if __name__ == '__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source',required=True,type=Path)
    parser.add_argument('--target',required=True,type=Path)
    arguments=parser.parse_args()
    try: print(json.dumps(compare(read_snapshot(arguments.source),read_snapshot(arguments.target)),indent=2))
    except (ValueError,TypeError,KeyError):
        parser.exit(1,'Snapshot validation failed. No database operation was performed.\n')
