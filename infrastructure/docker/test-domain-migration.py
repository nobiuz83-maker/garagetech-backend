"""Synthetic-only tests for the offline, no-write migration validator."""
import copy
import importlib.util
from pathlib import Path
import unittest
from uuid import uuid4

specification = importlib.util.spec_from_file_location('migration_validator',Path(__file__).with_name('validate-domain-migration.py'))
validator = importlib.util.module_from_spec(specification)
specification.loader.exec_module(validator)


class DomainMigrationTest(unittest.TestCase):
    def setUp(self):
        client,vehicle,appointment = str(uuid4()),str(uuid4()),str(uuid4())
        dates = {'created_at':'2024-01-02T03:04:05.123000Z','updated_at':'2025-02-03T04:05:06.456000Z','deleted_at':None}
        self.snapshot = {'schemaVersion':1,'ownerSubjects':['17'],'tables':{
            'clients':[{'id':client,'user_id':'17','first_name':'Fictional','last_name':'Migration','phone':'','email':'','address':{'city':'Test'},'data':{'schemaVersion':1,'additionalInformation':{'historicalOptional':None}},**dates}],
            'vehicles':[{'id':vehicle,'client_id':client,'make':'Test','model':'Model','year':2020,'vin':'','license_plate':'','data':{'schemaVersion':1,'unknownLegacyObject':{'items':[None,False,3]}},**dates}],
            'appointments':[{'id':appointment,'user_id':'17','client_id':client,'vehicle_id':vehicle,'date':'2027-01-08','start_time':'10:00:00','end_time':'11:00:00','status':'cancelled','service_type':'Test','description':'Fictional','notes':'','data':{'schemaVersion':1},'version':4,**dates}],
            'appointment_schedules':[],
        }}

    def test_preserves_uuids_dates_optional_values_and_unknown_json(self):
        target = validator.transform_source(self.snapshot)
        result = validator.compare(self.snapshot,target)
        self.assertTrue(result['valid'])
        self.assertEqual(result['historicalReferencesRequired'],1)
        self.assertFalse(result['databaseWritesPerformed'])

    def test_target_data_loss_is_rejected(self):
        target = validator.transform_source(self.snapshot)
        del target['tables']['vehicles'][0]['data']['unknownLegacyObject']
        with self.assertRaises(ValueError): validator.compare(self.snapshot,target)

    def test_unknown_sql_columns_and_identity_tables_are_rejected(self):
        snapshot = copy.deepcopy(self.snapshot)
        snapshot['tables']['clients'][0]['password'] = 'not-real'
        with self.assertRaises(ValueError): validator.validate(snapshot)
        snapshot = copy.deepcopy(self.snapshot)
        snapshot['tables']['users'] = []
        with self.assertRaises(ValueError): validator.validate(snapshot)

    def test_foreign_owners_and_orphan_relationships_are_rejected(self):
        snapshot = copy.deepcopy(self.snapshot)
        snapshot['ownerSubjects'].append('23')
        snapshot['tables']['appointments'][0]['user_id'] = '23'
        with self.assertRaises(ValueError): validator.validate(snapshot)
        snapshot = copy.deepcopy(self.snapshot)
        snapshot['tables']['vehicles'][0]['client_id'] = str(uuid4())
        with self.assertRaises(ValueError): validator.validate(snapshot)

    def test_archived_history_is_counted_and_no_restore_provenance_is_invented(self):
        for table in ['clients','vehicles','appointments']:
            self.snapshot['tables'][table][0]['deleted_at'] = '2025-02-03T04:05:06.456000Z'
        result = validator.compare(self.snapshot,validator.transform_source(self.snapshot))
        self.assertEqual(result['unknownArchiveProvenance'],{'clients':1,'vehicles':1})
        self.assertEqual(result['historicalReferencesRequired'],1)

    def test_unknown_versions_and_modified_dates_are_rejected(self):
        snapshot = copy.deepcopy(self.snapshot)
        snapshot['tables']['vehicles'][0]['data']['schemaVersion'] = 99
        with self.assertRaises(ValueError): validator.validate(snapshot)
        target = validator.transform_source(self.snapshot)
        target['tables']['clients'][0]['created_at'] = '2026-01-01T00:00:00.000000Z'
        with self.assertRaises(ValueError): validator.compare(self.snapshot,target)


    def test_null_documents_remain_null_and_conflicting_addresses_never_disappear(self):
        self.snapshot['tables']['clients'][0]['data'] = None
        self.snapshot['tables']['clients'][0]['address'] = None
        self.snapshot['tables']['vehicles'][0]['data'] = None
        transformed = validator.transform_source(self.snapshot)
        self.assertIsNone(transformed['tables']['clients'][0]['data'])
        self.assertIsNone(transformed['tables']['vehicles'][0]['data'])
        self.snapshot['tables']['clients'][0]['data'] = {'schemaVersion': 1, 'address': None}
        self.snapshot['tables']['clients'][0]['address'] = {'city': 'Conflicting'}
        with self.assertRaises(ValueError): validator.transform_source(self.snapshot)

    def test_invalid_dates_intervals_and_unrepresentable_json_integers_block_preparation(self):
        for changes in [{'date': '2027-02-30'}, {'end_time': '09:00:00'}, {'start_time': '25:00:00'}]:
            snapshot = copy.deepcopy(self.snapshot)
            snapshot['tables']['appointments'][0].update(changes)
            with self.assertRaises(ValueError): validator.validate(snapshot)
        self.snapshot['tables']['vehicles'][0]['data']['tooLarge'] = 9223372036854775808
        with self.assertRaises(ValueError): validator.validate(self.snapshot)

    def test_bundles_include_every_archived_reference_and_preserve_source_without_mutation(self):
        preparation_spec = importlib.util.spec_from_file_location('preparation', Path(__file__).with_name('prepare-domain-import.py'))
        preparation = importlib.util.module_from_spec(preparation_spec)
        preparation_spec.loader.exec_module(preparation)
        source_before = copy.deepcopy(self.snapshot)
        self.snapshot['tables']['appointments'][0]['deleted_at'] = '2025-03-04T05:06:07Z'
        source_before = copy.deepcopy(self.snapshot)
        bundles = preparation.build(self.snapshot, 'a' * 64, str(uuid4()), '2026-11-14 08:00:00.000')
        self.assertEqual(self.snapshot, source_before)
        self.assertEqual(len(bundles['clients']['tables']['appointment_references']), 1)
        self.assertEqual(bundles['clients']['referenceDigest'], bundles['appointments']['referenceDigest'])
        self.assertIsNotNone(bundles['appointments']['tables']['appointments'][0]['deleted_at'])
        self.snapshot['tables']['clients'][0]['created_at'] = '2024-01-02T03:04:05.123456Z'
        with self.assertRaises(ValueError): preparation.build(self.snapshot, 'a' * 64, str(uuid4()), '2026-11-14 08:00:00.000')

    def test_cross_service_receipts_require_matching_verified_digests_and_complete_history(self):
        receipt_spec = importlib.util.spec_from_file_location('receipts', Path(__file__).with_name('verify-domain-import-receipts.py'))
        receipts = importlib.util.module_from_spec(receipt_spec)
        receipt_spec.loader.exec_module(receipts)
        manifest = {'migrationId': str(uuid4()), 'sourceSha256': 'a' * 64, 'referenceDigest': 'b' * 64, 'historicalReferences': 1, 'files': {'clients.json': 'c' * 64, 'appointments.json': 'd' * 64}}
        common = {field: manifest[field] for field in ['migrationId', 'sourceSha256', 'referenceDigest']}
        clients = {**common, 'domain': 'clients', 'status': 'verified-existing', 'payloadSha256': 'c' * 64, 'registryActivated': False, 'counts': {'appointment_references': 1, 'appointment_subjects': 1}}
        appointments = {**common, 'domain': 'appointments', 'status': 'verified-existing', 'payloadSha256': 'd' * 64, 'registryActivated': False, 'counts': {'appointments': 1}}
        self.assertTrue(receipts.verify(manifest, clients, appointments)['valid'])
        clients['counts']['appointment_references'] = 0
        with self.assertRaises(ValueError): receipts.verify(manifest, clients, appointments)


    def test_ambiguous_json_and_boolean_schema_versions_are_rejected(self):
        import tempfile
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'ambiguous.json'
            path.write_text('{"schemaVersion":1,"schemaVersion":2}')
            with self.assertRaises(ValueError): validator.read_snapshot(path)
        self.snapshot['schemaVersion'] = True
        with self.assertRaises(ValueError): validator.validate(self.snapshot)



if __name__ == '__main__': unittest.main()
