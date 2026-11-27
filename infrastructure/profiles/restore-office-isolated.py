"""Restore an OFFICE backup into a new retained MariaDB volume with no network."""
import gzip
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
from uuid import uuid4

WORKSPACE = Path(__file__).resolve().parents[2] / '.private' / 'office'


def command(arguments: list[str], input_bytes: bytes | None = None) -> subprocess.CompletedProcess:
    return subprocess.run(arguments, input=input_bytes, capture_output=True, check=True)


def restore(backup_directory: Path) -> None:
    manifest = json.loads((backup_directory / 'manifest.json').read_text())
    if manifest.get('profile') != 'office' or manifest.get('project') != 'vicartech-lot1-integration-office':
        raise RuntimeError('Archive does not belong to the isolated OFFICE project.')
    expected_databases = {'auth_db', *(f'vicartech_integration_{name}' for name in ('clients', 'appointments', 'inventory', 'interventions', 'billing', 'accounting'))}
    if set(manifest['databases']) != expected_databases or (backup_directory / 'INCOMPLETE').exists():
        raise RuntimeError('Backup is incomplete or has unexpected databases.')
    for entry in manifest['databases'].values():
        archive = backup_directory / entry['filename']
        if not archive.is_file() or hashlib.sha256(archive.read_bytes()).hexdigest() != entry['sha256']:
            raise RuntimeError(f'Backup archive checksum failed: {archive.name}')

    os.umask(0o077)
    check_id = uuid4().hex[:10]
    check_directory = WORKSPACE / 'restore-checks' / check_id
    check_directory.mkdir(parents=True, mode=0o700)
    password_file = check_directory / 'root-password'
    password_file.write_text(secrets.token_urlsafe(32))
    password_file.chmod(0o600)
    command(['setfacl', '-m', 'u:999:r', str(password_file)])
    volume_name = f'vicartech_office_restore_check_{check_id}'
    container_name = f'vicartech-office-restore-check-{check_id}'
    command(['docker', 'volume', 'create', volume_name])
    command(['docker', 'run', '--detach', '--name', container_name, '--network', 'none',
             '--volume', f'{volume_name}:/var/lib/mysql',
             '--volume', f'{password_file}:/run/secrets/restore-root-password:ro',
             '--env', 'MARIADB_ROOT_PASSWORD_FILE=/run/secrets/restore-root-password',
             'mariadb:10.6.28-ubi9'])
    try:
        for attempt in range(60):
            result = subprocess.run(['docker', 'exec', container_name, 'healthcheck.sh', '--connect', '--innodb_initialized'], capture_output=True)
            if result.returncode == 0:
                break
            container_status = command(['docker', 'inspect', '--format', '{{.State.Status}}', container_name]).stdout.decode().strip()
            if container_status != 'running':
                raise RuntimeError(f'Isolated MariaDB stopped before becoming ready: {container_status}')
            time.sleep(1)
        else:
            raise RuntimeError('Isolated MariaDB did not become ready.')
        for database_name, entry in manifest['databases'].items():
            with gzip.open(backup_directory / entry['filename'], 'rb') as archive:
                process = subprocess.Popen(['docker', 'exec', '-i', container_name, 'sh', '-c',
                    'MYSQL_PWD="$(cat /run/secrets/restore-root-password)" exec mariadb --user=root'],
                    stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
                while chunk := archive.read(1024 * 1024):
                    process.stdin.write(chunk)
                process.stdin.close()
                error = process.stderr.read().decode(errors='replace')
                if process.wait() != 0:
                    raise RuntimeError(f'Restore failed for {database_name}: {error[-300:]}')
            query = f'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema="{database_name}"'
            result = command(['docker', 'exec', container_name, 'sh', '-c',
                'MYSQL_PWD="$(cat /run/secrets/restore-root-password)" exec mariadb --user=root --skip-column-names --execute="$1"',
                'restore-check', query])
            if int(result.stdout.strip()) < 1:
                raise RuntimeError(f'No tables restored for {database_name}.')
        print(f'OFFICE backup restored into isolated retained volume {volume_name}; seven databases verified.')
    finally:
        subprocess.run(['docker', 'stop', container_name], check=False, capture_output=True)


if __name__ == '__main__':
    if len(sys.argv) != 2:
        raise SystemExit('Usage: restore-office-isolated.py BACKUP_DIRECTORY')
    restore(Path(sys.argv[1]).resolve())
