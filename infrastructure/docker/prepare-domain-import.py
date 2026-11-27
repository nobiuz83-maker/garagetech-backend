"""Build independently reviewable domain bundles from an approved offline snapshot.

No database connection is opened. The output contains no identity profiles or credentials.
Each Laravel application imports only its own bundle into its own candidate database.
"""
import argparse
from datetime import datetime, timezone
import hashlib
import importlib.util
import json
import os
from pathlib import Path
from uuid import uuid4

specification = importlib.util.spec_from_file_location('domain_validator', Path(__file__).with_name('validate-domain-migration.py'))
validator = importlib.util.module_from_spec(specification)
specification.loader.exec_module(validator)


def sql_timestamp(value, field):
    if value is None:
        return None
    parsed = datetime.fromisoformat(value.replace('Z', '+00:00'))
    if parsed.tzinfo is not None:
        parsed = parsed.astimezone(timezone.utc)
    precision = 0 if field == 'deleted_at' else 3
    if parsed.microsecond % (10 ** (6 - precision)):
        raise ValueError('Timestamp precision exceeds the target SQL column; explicit migration required')
    formatted = parsed.strftime('%Y-%m-%d %H:%M:%S')
    return formatted + (f'.{parsed.microsecond // 1000:03d}' if precision else '')


def reference_digest(references):
    lines = sorted('|'.join(str(record[column]) for column in ['appointment_id', 'client_id', 'vehicle_id', 'owner_subject_id']) for record in references)
    return hashlib.sha256('\n'.join(lines).encode()).hexdigest()


def build(source, source_sha256, migration_id, recorded_at):
    transformed = validator.transform_source(source)
    tables = transformed['tables']
    for records in tables.values():
        for record in records:
            for field in ['created_at', 'updated_at', 'deleted_at']:
                if field in record:
                    record[field] = sql_timestamp(record[field], field)
    references = [{'appointment_id': appointment['id'], 'client_id': appointment['client_id'], 'vehicle_id': appointment['vehicle_id'], 'owner_subject_id': appointment['user_id'], 'recorded_at': recorded_at} for appointment in tables['appointments']]
    subjects = [{'appointment_id': appointment['id'], 'owner_subject_id': appointment['user_id']} for appointment in tables['appointments']]
    associations = {(appointment['client_id'], appointment['vehicle_id'], appointment['user_id']) for appointment in tables['appointments']}
    common = {'schemaVersion': 1, 'migrationId': migration_id, 'sourceSha256': source_sha256, 'referenceDigest': reference_digest(references), 'ownerSubjects': transformed['ownerSubjects']}
    return {
        'clients': {**common, 'domain': 'clients', 'tables': {'clients': tables['clients'], 'vehicles': tables['vehicles'], 'appointment_subjects': subjects, 'appointment_references': references}},
        'appointments': {**common, 'domain': 'appointments', 'tables': {'client_vehicle_associations': [{'client_id': client, 'vehicle_id': vehicle, 'user_id': owner} for client, vehicle, owner in sorted(associations)], 'appointments': tables['appointments'], 'appointment_schedules': tables['appointment_schedules']}},
    }


def prepare(source_path, directory, migration_id):
    source_bytes = source_path.read_bytes()
    source = validator.read_snapshot(source_path)
    bundles = build(source, hashlib.sha256(source_bytes).hexdigest(), migration_id, datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S.000'))
    os.umask(0o077)
    directory.mkdir(mode=0o700, parents=True, exist_ok=False)
    manifest = {'migrationId': migration_id, 'sourceSha256': hashlib.sha256(source_bytes).hexdigest(), 'referenceDigest': bundles['clients']['referenceDigest'], 'historicalReferences': len(source['tables']['appointments']), 'files': {}}
    for domain, bundle in bundles.items():
        path = directory / (domain + '.json')
        path.write_text(json.dumps(bundle, ensure_ascii=False, allow_nan=False, indent=2) + '\n')
        manifest['files'][path.name] = hashlib.sha256(path.read_bytes()).hexdigest()
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    return manifest


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--migration-id', default=str(uuid4()))
    arguments = parser.parse_args()
    try:
        print(json.dumps(prepare(arguments.source, arguments.output, validator.identifier(arguments.migration_id)), indent=2))
    except (ValueError, TypeError, KeyError):
        parser.exit(1, 'Snapshot validation failed; no database was accessed.\n')
