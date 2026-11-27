"""Create an atomic, private backup of all seven isolated OFFICE databases."""
import hashlib
import json
import os
from pathlib import Path
import subprocess
from datetime import datetime, timezone

WORKSPACE = Path(__file__).resolve().parents[2] / '.private' / 'office'
CONFIGURATION = WORKSPACE / 'compose.json'
BACKUPS = WORKSPACE / 'backups'
SERVICES = ('auth', 'clients', 'appointments', 'inventory', 'interventions', 'billing', 'accounting')


def back_up() -> None:
    if not CONFIGURATION.is_file():
        raise RuntimeError('OFFICE configuration is missing.')
    os.umask(0o077)
    BACKUPS.mkdir(mode=0o700, exist_ok=True)
    BACKUPS.chmod(0o700)
    backup_directory = BACKUPS / datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%SZ')
    backup_directory.mkdir(mode=0o700)
    manifest = {'profile': 'office', 'project': 'vicartech-lot1-integration-office', 'databases': {}}
    try:
        for service in SERVICES:
            database_name = 'auth_db' if service == 'auth' else f'vicartech_integration_{service}'
            process = subprocess.Popen([
                'docker', 'compose', '-f', str(CONFIGURATION), 'exec', '-T', f'database-{service}',
                'sh', '-c',
                'MYSQL_PWD="$MARIADB_ROOT_PASSWORD" exec mariadb-dump --user=root '
                '--single-transaction --quick --routines --triggers --events --databases "$1"',
                'office-backup', database_name,
            ], stdout=subprocess.PIPE, stderr=subprocess.PIPE)
            import gzip
            archive = backup_directory / f'{database_name}.sql.gz'
            with gzip.open(archive, 'wb', compresslevel=9) as output:
                while chunk := process.stdout.read(1024 * 1024):
                    output.write(chunk)
            error = process.stderr.read().decode(errors='replace')
            if process.wait() != 0 or archive.stat().st_size < 100:
                raise RuntimeError(f'Backup failed for {database_name}: {error[-300:]}')
            with gzip.open(archive, 'rb') as source:
                while source.read(1024 * 1024):
                    pass
            archive.chmod(0o600)
            manifest['databases'][database_name] = {
                'filename': archive.name,
                'sha256': hashlib.sha256(archive.read_bytes()).hexdigest(),
            }
        manifest_path = backup_directory / 'manifest.json'
        manifest_path.write_text(json.dumps(manifest, indent=2))
        manifest_path.chmod(0o600)
        print(f'OFFICE backup complete: {backup_directory}')
    except Exception:
        (backup_directory / 'INCOMPLETE').write_text('Do not restore this backup.\n')
        raise


if __name__ == '__main__':
    back_up()
