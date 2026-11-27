"""Encrypt and restore only synthetic domain databases into a new isolated MariaDB volume.

Never accepts a production container, never overwrites a database or removes a volume.
The encrypted backup excludes Auth users, password hashes and sessions.
"""
import argparse
import hashlib
import json
import os
import re
from pathlib import Path
import secrets
import subprocess
import time


def command(arguments, body=None):
    return subprocess.run(arguments,input=body,capture_output=True,check=True,timeout=90).stdout


def rehearse(workspace, source='vicartech-auth-e2e-validation-database-1', databases=None, source_secret='/run/secrets/stage1_root_password'):
    os.umask(0o077)
    workspace.mkdir(parents=True,mode=0o700,exist_ok=False)
    metadata=json.loads(command(['docker','inspect',source]))[0]
    project = metadata['Config']['Labels'].get('com.docker.compose.project')
    databases = databases or ['vicartech_clients', 'vicartech_appointments']
    legacy_test = project == 'vicartech-auth-e2e-validation' and source == project + '-database-1' and databases == ['vicartech_clients', 'vicartech_appointments'] and source_secret == '/run/secrets/stage1_root_password'
    maintenance_test = isinstance(project, str) and re.fullmatch(r'vicartech-maintenance-test-[a-f0-9]{8}', project) and source == project + '-database' and len(databases) == 2 and set(databases) == {'vicartech_test_clients_' + project[-8:], 'vicartech_test_appointments_' + project[-8:]} and source_secret == '/run/maintenance_root'
    if not (legacy_test or maintenance_test):
        raise ValueError('Only explicitly named synthetic validation databases may be backed up')
    dump=command(['docker','exec',source,'sh','-c','MYSQL_PWD="$(cat '+source_secret+')" mariadb-dump -uroot --single-transaction --skip-lock-tables --skip-add-drop-table --skip-comments --hex-blob --order-by-primary --databases '+' '.join(databases)])
    passphrase=workspace/'backup-passphrase';passphrase.write_text(secrets.token_urlsafe(48))
    gnupg=workspace/'gnupg';gnupg.mkdir(mode=0o700)
    encrypted=workspace/'domain-backup.sql.gpg'
    gpg=['gpg','--homedir',str(gnupg),'--batch','--pinentry-mode','loopback','--passphrase-file',str(passphrase)]
    command([*gpg,'--symmetric','--cipher-algo','AES256','--compress-algo','none','--output',str(encrypted)],dump)
    restored=command([*gpg,'--decrypt',str(encrypted)])
    assert restored==dump
    # Corruption must be detected before any SQL is executed.
    corrupted=bytearray(encrypted.read_bytes());corrupted[len(corrupted)//2]^=1
    tampered=subprocess.run([*gpg,'--decrypt'],input=bytes(corrupted),capture_output=True,timeout=90)
    assert tampered.returncode!=0
    root_password=workspace/'restore-root-password';root_password.write_text(secrets.token_urlsafe(48))
    suffix=secrets.token_hex(4);container='vicartech-auth-e2e-restore-'+suffix;volume=container+'-data'
    command(['docker','volume','create',volume])
    command(['docker','run','-d','--name',container,'--network','none','--user','0:0','-e','MARIADB_ROOT_PASSWORD_FILE=/run/restore_root','--mount',f'type=bind,source={root_password},target=/run/restore_root,readonly','--mount',f'type=volume,source={volume},target=/var/lib/mysql','mariadb:10.6.28-ubi9'])
    try:
        for attempt in range(80):
            result=subprocess.run(['docker','exec',container,'healthcheck.sh','--connect','--innodb_initialized'],capture_output=True)
            if result.returncode==0:break
            time.sleep(.5)
        else:raise RuntimeError('Isolated restore database did not become ready')
        command(['docker','exec','-i',container,'sh','-c','MYSQL_PWD="$(cat /run/restore_root)" mariadb -uroot'],restored)
        def checksums(name,secret):
            statements=[]
            for database in databases:
                tables=command(['docker','exec','-i',name,'sh','-c',f'MYSQL_PWD="$(cat {secret})" mariadb -uroot -N'],('SHOW TABLES FROM '+database+';').encode()).decode().splitlines()
                statements.extend('CHECKSUM TABLE '+database+'.'+table+' EXTENDED;' for table in tables)
            return command(['docker','exec','-i',name,'sh','-c',f'MYSQL_PWD="$(cat {secret})" mariadb -uroot -N'],'\n'.join(statements).encode()).decode()
        source_checks=checksums(source,source_secret)
        restored_checks=checksums(container,'/run/restore_root')
        assert source_checks==restored_checks
        report={'encryptedBackup':True,'tamperRejected':True,'restoredChecksumsMatch':True,'tablesVerified':len(source_checks.splitlines()),'backupSha256':hashlib.sha256(encrypted.read_bytes()).hexdigest(),'newVolumeRetained':volume,'realDataAccessed':False,'usersOrSessionsCopied':False}
        (workspace/'report.json').write_text(json.dumps(report,indent=2));print(json.dumps(report))
    finally:
        command(['docker','stop',container])


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--workspace',required=True,type=Path)
    parser.add_argument('--source-container', default='vicartech-auth-e2e-validation-database-1')
    parser.add_argument('--databases', nargs=2)
    parser.add_argument('--source-secret', default='/run/secrets/stage1_root_password')
    arguments=parser.parse_args();rehearse(arguments.workspace,arguments.source_container,arguments.databases,arguments.source_secret)
