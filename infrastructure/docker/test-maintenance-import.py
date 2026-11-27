"""Rehearse maintenance imports and manual recovery using new synthetic MariaDB schemas.

Creates a private Docker network, a fresh retained database volume and restricted accounts.
No production stack, source database or existing volume is modified or removed.
"""
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import time
from uuid import uuid4

BACKEND = Path(__file__).resolve().parents[2]


def command(arguments, body=None, check=True):
    return subprocess.run(arguments, input=body, capture_output=True, check=check, timeout=120)


def run(workspace):
    os.umask(0o077)
    workspace.mkdir(parents=True, mode=0o700, exist_ok=False)
    suffix = secrets.token_hex(4)
    project = 'vicartech-maintenance-test-' + suffix
    container = project + '-database'
    network = project + '-network'
    volume = project + '-data'
    password = secrets.token_hex(32)
    root_secret = workspace / 'root-password'
    root_secret.write_text(password)
    databases = {domain: 'vicartech_test_' + domain + '_' + suffix for domain in ['clients', 'appointments']}
    checks = []

    def passed(name):
        checks.append({'name': name, 'status': 'passed'})

    def sql(statement):
        return command(['docker', 'exec', '-i', container, 'sh', '-c', 'MYSQL_PWD="$(cat /run/maintenance_root)" mariadb -uroot -N'], statement.encode()).stdout.decode()

    def cli(domain, *arguments, check=True):
        application = BACKEND / 'services' / (domain + '-service')
        return command(['docker', 'run', '--rm', '--user', str(os.getuid()) + ':' + str(os.getgid()), '--network', network, '--entrypoint', 'php', '--env-file', str(workspace / (domain + '.env')),
                        '--mount', f'type=bind,source={application},target=/var/www/html,readonly',
                        '--mount', f'type=bind,source={workspace / (domain + "-storage")},target=/var/www/html/storage',
                        '--mount', f'type=bind,source={workspace / "bundles"},target=/run/approved,readonly',
                        'vicartech-laravel-runtime:stage1', 'artisan', *arguments], check=check)

    try:
        command(['docker', 'network', 'create', '--internal', network])
        command(['docker', 'volume', 'create', volume])
        command(['docker', 'run', '-d', '--name', container, '--label', 'com.docker.compose.project=' + project,
                 '--network', network, '--network-alias', 'database', '--user', '0:0', '-e', 'MARIADB_ROOT_PASSWORD_FILE=/run/maintenance_root',
                 '--mount', f'type=bind,source={root_secret},target=/run/maintenance_root,readonly',
                 '--mount', f'type=volume,source={volume},target=/var/lib/mysql', 'mariadb:10.6.28-ubi9'])
        for attempt in range(100):
            if command(['docker', 'exec', container, 'healthcheck.sh', '--connect', '--innodb_initialized'], check=False).returncode == 0:
                break
            time.sleep(.3)
        else:
            raise RuntimeError('Synthetic database did not become ready')
        client_fixture = json.loads((BACKEND / 'services/clients-service/tests/Fixtures/maintenance-snapshot.json').read_text())
        appointment_fixture = json.loads((BACKEND / 'services/appointments-service/tests/Fixtures/maintenance-snapshot.json').read_text())
        source = {'schemaVersion': 1, 'ownerSubjects': client_fixture['ownerSubjects'], 'tables': {
            **{table: client_fixture['tables'][table] for table in ['clients', 'vehicles']},
            **{table: appointment_fixture['tables'][table] for table in ['appointments', 'appointment_schedules']},
        }}
        source_path = workspace / 'synthetic-source.json'
        source_path.write_text(json.dumps(source, indent=2))
        preparation = command(['python3', str(BACKEND / 'infrastructure/docker/prepare-domain-import.py'), '--source', str(source_path), '--output', str(workspace / 'bundles')])
        manifest = json.loads(preparation.stdout)
        for domain, database in databases.items():
            user_password = secrets.token_hex(32)
            sql(f"CREATE DATABASE `{database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER '{domain}'@'%' IDENTIFIED BY '{user_password}'; GRANT ALL PRIVILEGES ON `{database}`.* TO '{domain}'@'%';")
            storage = workspace / (domain + '-storage')
            for directory in ['logs', 'framework/cache/data', 'framework/sessions', 'framework/views', 'app/private']:
                (storage / directory).mkdir(parents=True, exist_ok=True)
            settings = {'APP_ENV': 'local', 'APP_DEBUG': 'false', 'APP_KEY': 'base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=',
                        'CACHE_STORE': 'array', 'SESSION_DRIVER': 'array', 'QUEUE_CONNECTION': 'sync', 'DB_CONNECTION': 'mysql',
                        'DB_HOST': 'database', 'DB_PORT': '3306', 'DB_DATABASE': database, 'DB_USERNAME': domain, 'DB_PASSWORD': user_password,
                        'MAINTENANCE_IMPORT_ENABLED': 'true'}
            (workspace / (domain + '.env')).write_text('\n'.join(key + '=' + value for key, value in settings.items()) + '\n')
            cli(domain, 'migrate', '--force', '--no-interaction')
            approved = '--sha256=' + manifest['files'][domain + '.json']
            cli(domain, 'domain:import', '/run/approved/' + domain + '.json', approved)
            denied = cli(domain, 'domain:import', '/run/approved/' + domain + '.json', approved, '--apply', check=False)
            assert denied.returncode != 0, 'An application outside maintenance must refuse writes'
            settings_path = workspace / (domain + '.env')
            settings_path.write_text(settings_path.read_text().replace('APP_ENV=local', 'APP_ENV=testing'))
            denied_testing = cli(domain, 'domain:import', '/run/approved/' + domain + '.json', approved, '--apply', check=False)
            assert denied_testing.returncode != 0, 'APP_ENV alone must not bypass maintenance mode'
            settings_path.write_text(settings_path.read_text().replace('APP_ENV=testing', 'APP_ENV=local'))
            cli(domain, 'down', '--no-interaction')
        passed('Dry runs write nothing; both real MariaDB imports require application maintenance mode')
        target = databases['clients']
        sql(f"CREATE TRIGGER `{target}`.reject_synthetic BEFORE INSERT ON `{target}`.vehicles FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic fault';")
        failure = cli('clients', 'domain:import', '/run/approved/clients.json', '--sha256=' + manifest['files']['clients.json'], '--apply', check=False)
        assert failure.returncode != 0
        assert sql(f'SELECT COUNT(*) FROM `{target}`.clients; SELECT COUNT(*) FROM `{target}`.owner_subjects; SELECT COUNT(*) FROM `{target}`.maintenance_imports;').splitlines() == ['0', '0', '0']
        sql(f'DROP TRIGGER `{target}`.reject_synthetic;')
        passed('An injected MariaDB write failure rolls back domain rows, owner projections and import receipt')
        receipts = {}
        for domain in databases:
            approved = '--sha256=' + manifest['files'][domain + '.json']
            assert json.loads(cli(domain, 'domain:import', '/run/approved/' + domain + '.json', approved, '--apply').stdout)['status'] == 'imported'
            assert json.loads(cli(domain, 'domain:import', '/run/approved/' + domain + '.json', approved, '--apply').stdout)['status'] == 'verified-existing'
            receipts[domain] = json.loads(cli(domain, 'domain:import', '/run/approved/' + domain + '.json', approved, '--verify').stdout)
            (workspace / (domain + '-receipt.json')).write_text(json.dumps(receipts[domain]))
        verifier = command(['python3', str(BACKEND / 'infrastructure/docker/verify-domain-import-receipts.py'), '--manifest', str(workspace / 'bundles/manifest.json'), '--clients', str(workspace / 'clients-receipt.json'), '--appointments', str(workspace / 'appointments-receipt.json')])
        assert json.loads(verifier.stdout)['valid'] is True
        passed('Full content verification and idempotent imports preserve UUIDs, dates, statuses, JSON and every archived reference')
        plan = {'schemaVersion': 1, 'actionId': str(uuid4()), 'operation': 'restore', 'reversesActionId': None, 'operator': 'synthetic-operator', 'reviewer': 'synthetic-reviewer',
                'reason': 'Explicit synthetic legacy restoration test; historical cause remains unknown', 'ticket': 'SYNTHETIC-RECOVERY',
                'records': [{'entityType': entity, 'id': client_fixture['tables'][table][0]['id'], 'expectedSha256': None} for entity, table in [('client', 'clients'), ('vehicle', 'vehicles')]]}
        plan_path = workspace / 'bundles/restore-plan.json'
        def save_plan(value):
            plan_path.write_text(json.dumps(value, indent=2))
            return '--sha256=' + hashlib.sha256(plan_path.read_bytes()).hexdigest()
        preview = json.loads(cli('clients', 'archives:restore', '/run/approved/restore-plan.json', save_plan(plan)).stdout)
        for record, reviewed in zip(plan['records'], preview['records']):
            record['expectedSha256'] = reviewed['beforeSha256']
        approval = save_plan(plan)
        assert json.loads(cli('clients', 'archives:restore', '/run/approved/restore-plan.json', approval, '--apply').stdout)['status'] == 'restored'
        # Guarded rollback uses the recorded post-state hashes, never a guessed historical cascade.
        changes = json.loads(sql(f"SELECT records FROM `{target}`.manual_archive_actions WHERE id='{plan['actionId']}';"))['records']
        rollback = {**plan, 'actionId': str(uuid4()), 'operation': 'rollback', 'reversesActionId': plan['actionId'], 'records': [
            {'entityType': 'client' if change['table'] == 'clients' else 'vehicle', 'id': change['id'], 'expectedSha256': change['afterSha256']} for change in changes]}
        rollback_approval = save_plan(rollback)
        assert json.loads(cli('clients', 'archives:restore', '/run/approved/restore-plan.json', rollback_approval, '--apply').stdout)['status'] == 'rolled-back'
        assert sql(f'SELECT COUNT(*) FROM `{target}`.manual_archive_actions; SELECT COUNT(*) FROM `{target}`.archive_events;').splitlines() == ['2', '0']
        passed('Manual legacy restore and guarded rollback retain complete record content and append two audit decisions without inventing archive events')
        command(['docker', 'restart', container])
        for attempt in range(100):
            if command(['docker', 'exec', container, 'healthcheck.sh', '--connect', '--innodb_initialized'], check=False).returncode == 0:
                break
            time.sleep(.3)
        else:
            raise RuntimeError('Database restart failed')
        for domain in databases:
            cli(domain, 'domain:import', '/run/approved/' + domain + '.json', '--sha256=' + manifest['files'][domain + '.json'], '--verify')
        passed('Both complete snapshots and retained audit survive a real Docker database restart')
        # Use each service account to demonstrate that peer-schema reads are forbidden.
        for domain, peer in [('clients', 'appointments'), ('appointments', 'clients')]:
            credentials = dict(line.split('=', 1) for line in (workspace / (domain + '.env')).read_text().splitlines())
            config = '[client]\nuser=' + domain + '\npassword=' + credentials['DB_PASSWORD'] + '\n'
            # Credentials stay on stdin and in a private temporary file inside the isolated container.
            result = command(['docker', 'exec', '-i', container, 'sh', '-c', 'umask 077; cat > /tmp/probe.cnf; mariadb --defaults-extra-file=/tmp/probe.cnf -h database -N -e "SHOW TABLES FROM ' + databases[peer] + '"'], config.encode(), check=False)
            assert result.returncode != 0 and (b'1044' in result.stderr or b'1142' in result.stderr)
        passed('Candidate service accounts cannot read the other microservice database')
        backup = command(['python3', str(BACKEND / 'infrastructure/docker/rehearse-domain-backup.py'), '--workspace', str(workspace / 'backup'),
                          '--source-container', container, '--databases', databases['clients'], databases['appointments'], '--source-secret', '/run/maintenance_root'])
        assert json.loads(backup.stdout)['restoredChecksumsMatch'] is True
        passed('Encrypted synthetic backup restores into another retained volume with identical checksums and tamper rejection')
        report = {'passed': len(checks), 'failed': 0, 'checks': checks, 'project': project, 'databases': databases,
                  'volumeRetained': volume, 'sourceDataAccessed': False, 'usersCopied': False, 'routingActivated': False, 'registryActivated': False,
                  'backup': json.loads(backup.stdout)}
        (workspace / 'report.json').write_text(json.dumps(report, indent=2))
        print(json.dumps(report))
    finally:
        # Keep all records, the container and its named volume for inspection. No Docker deletion.
        command(['docker', 'stop', container], check=False)


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--workspace', type=Path, required=True)
    arguments = parser.parse_args()
    run(arguments.workspace)
