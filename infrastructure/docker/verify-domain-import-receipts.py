"""Verify independently exported local receipts; never activates routing or a registry."""
import argparse
import json
from pathlib import Path


def verify(manifest, clients, appointments):
    for domain, receipt in [('clients', clients), ('appointments', appointments)]:
        if receipt['domain'] != domain or receipt['status'] != 'verified-existing':
            raise ValueError('Both applications must explicitly verify their stored candidate data')
        for field in ['migrationId', 'sourceSha256', 'referenceDigest']:
            if receipt[field] != manifest[field]:
                raise ValueError('The two candidates do not describe the same approved snapshot')
        if receipt['payloadSha256'] != manifest['files'][domain + '.json'] or receipt['registryActivated'] is not False:
            raise ValueError('Unapproved payload or unexpected activation')
    required = manifest['historicalReferences']
    if clients['counts']['appointment_references'] != required or clients['counts']['appointment_subjects'] != required or appointments['counts']['appointments'] != required:
        raise ValueError('Historical reference preload is incomplete')
    return {'valid': True, 'historicalReferences': required, 'referenceDigest': manifest['referenceDigest'], 'registryActivated': False, 'routingActivated': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    for field in ['manifest', 'clients', 'appointments']:
        parser.add_argument('--' + field, type=Path, required=True)
    arguments = parser.parse_args()
    try:
        print(json.dumps(verify(*(json.loads(getattr(arguments, field).read_text()) for field in ['manifest', 'clients', 'appointments'])), indent=2))
    except (ValueError, TypeError, KeyError):
        parser.exit(1, 'Candidate verification failed; registry and routing must remain disabled.\n')
