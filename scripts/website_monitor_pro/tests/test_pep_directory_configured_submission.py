"""Persisted-only configured ingestion; synthetic fixtures on the authorized copy."""
import base64
import json
from concurrent.futures import ThreadPoolExecutor
from queue import Queue
from threading import Event
from time import monotonic

if __package__:
    from . import test_pep_directory_configured_admission as admission
    from .test_pep_directory_preview import request
else:
    import test_pep_directory_configured_admission as admission
    from test_pep_directory_preview import request
from pep_directory_preview import compile_preview


class ConfiguredSubmissionTest(admission.ConfiguredAdmissionTest):
    def configured(self):
        value, fuente_id = self.source(configurable=True)
        source_id = self.repo.state(value.source)['source_id']
        envelope = request()
        envelope['declaration']['source'] = value.source
        artifact = compile_preview(envelope)
        with self.connection, self.connection.cursor() as cursor:
            cursor.execute('INSERT INTO pep_directory_configurations '
                           '(source_id,declaration,canonical_bytes,revision,binding_url,directory_url,creator_identity,creator_snapshot) '
                           'VALUES (%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id',
                           (source_id, json.dumps(envelope['declaration']), base64.b64decode(artifact['configuration']['canonical_base64']),
                            artifact['configuration']['revision'], envelope['metadata']['source_url'], envelope['metadata']['source_url'],
                            'synthetic-fixture', json.dumps({'synthetic': True})))
            config_id = cursor.fetchone()[0]
            cursor.execute('UPDATE pep_directory_sources SET eligibility_version=1 WHERE id=%s', (source_id,))
        data = dict(source=value.source, source_id=source_id, fuente_id=fuente_id, configuration_id=config_id,
                    declaration=envelope['declaration'], expected_version=1)
        self.capture(data)
        self.approve(data)
        return data

    def capture(self, data, person='Synthetic Alpha', day=18, body=None, status=200):
        envelope = request(body if body is not None else
                           f'<html><body><table><tr><td>Director</td><td>{person}</td></tr></table></body></html>'.encode())
        envelope['declaration'] = data['declaration']
        envelope['metadata'].update(observed_at=f'2026-10-{day:02}T12:00:00Z', status=status)
        artifact = compile_preview(envelope)
        capture = artifact['capture']
        with self.connection, self.connection.cursor() as cursor:
            cursor.execute('SELECT id FROM fuentes WHERE id=%s FOR UPDATE', (data['fuente_id'],))
            cursor.execute('INSERT INTO pep_directory_captures '
                           '(source_id,body_bytes,content_sha256,metadata,provenance,captured_at) VALUES (%s,%s,%s,%s,%s,%s) RETURNING id',
                           (data['source_id'], base64.b64decode(capture['body_base64']), capture['content_sha256'],
                            json.dumps(capture['metadata']), json.dumps({'synthetic': True, 'requested_url': envelope['metadata']['source_url'],
                                                                      'final_url': envelope['metadata']['source_url']}),
                            capture['metadata']['observed_at']))
            data['capture_id'] = cursor.fetchone()[0]
            cursor.execute('UPDATE pep_directory_sources SET eligibility_version=eligibility_version+1 WHERE id=%s RETURNING eligibility_version',
                           (data['source_id'],))
            data['expected_version'] = cursor.fetchone()[0]
        data['artifact'] = artifact

    def approve(self, data):
        preview = data['artifact']['preview']
        with self.connection, self.connection.cursor() as cursor:
            cursor.execute('SELECT id FROM fuentes WHERE id=%s FOR UPDATE', (data['fuente_id'],))
            cursor.execute('SELECT eligibility_version FROM pep_directory_sources WHERE id=%s FOR UPDATE', (data['source_id'],))
            version = cursor.fetchone()[0]
            cursor.execute('INSERT INTO pep_directory_previews '
                           '(source_id,configuration_id,capture_id,result_bytes,result_sha256,wire_payload,validity,qualification,eligibility_version) '
                           'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id',
                           (data['source_id'], data['configuration_id'], data['capture_id'], base64.b64decode(preview['result_base64']),
                            preview['result_sha256'], json.dumps(preview['wire']), preview['validity'],
                            json.dumps({'state': 'candidate', 'authority': 'none'}), version + 1))
            preview_id = cursor.fetchone()[0]
            cursor.execute("SELECT nextval(pg_get_serial_sequence('pep_directory_configuration_decisions','id'))")
            approval_id = cursor.fetchone()[0]
            cursor.execute('INSERT INTO pep_directory_configuration_decisions '
                           '(id,source_id,sequence,action,configuration_id,preview_id,prior_approval_id,new_approval_id,actor_kind,actor_identity,actor_snapshot,reason) '
                           "VALUES (%s,%s,%s,'approve',%s,%s,%s,%s,'human','synthetic-fixture',%s,'Synthetic approval fixture, not a real human decision')",
                           (approval_id, data['source_id'], version + 2, data['configuration_id'], preview_id,
                            data.get('approval_decision_id'), approval_id, json.dumps({'synthetic': True})))
            cursor.execute('UPDATE pep_directory_sources SET draft_config_id=%s,current_preview_id=%s,approved_decision_id=%s,eligibility_version=%s WHERE id=%s',
                           (data['configuration_id'], preview_id, approval_id, version + 2, data['source_id']))
        data.update(approval_decision_id=approval_id, expected_version=version + 2, preview_id=preview_id)

    def ingest(self, data, submission='one', **overrides):
        args = {key: data[key] for key in ('configuration_id', 'capture_id', 'approval_decision_id', 'expected_version')}
        args.update(overrides)
        return self.repo.submit_configured(data['source'], submission_id=submission, **args)

    def test_approved_stored_capture_establishes_baseline_with_actual_lineage(self):
        data = self.configured()
        result = self.ingest(data)
        self.assertEqual(result['disposition'], 'baseline')
        self.assertEqual(result['events'], [])
        self.assertEqual(result['version'], 1)
        self.assertEqual(result['observation']['validity'], 'valid')
        self.assertEqual(result['observation']['positions'][0]['person'], 'Synthetic Alpha')
        self.assertEqual(result['content_sha256'], data['artifact']['capture']['content_sha256'])
        for key in ('configuration_id', 'capture_id', 'approval_decision_id'):
            self.assertEqual(result[key], data[key])
        self.assertEqual(self.ingest(data), result)
        self.assertEqual(self.repo.history(data['source']), [result])
        self.assertEqual(self.repo.state(data['source'])['baseline'], result['observation'])

    def refused_configured(self, data, submission='refused', **overrides):
        before = self.repo.state(data['source']), self.repo.history(data['source'])
        with self.assertRaisesRegex(ValueError, '^configured submission refused$'):
            self.ingest(data, submission, **overrides)
        self.assertEqual((self.repo.state(data['source']), self.repo.history(data['source'])), before)

    def test_capture_freshness_advances_without_requiring_new_approval(self):
        data = self.configured()
        first = self.ingest(data)
        frozen = dict(data)
        self.capture(data, 'Synthetic Beta', 19)
        self.refused_configured(data, expected_version=frozen['expected_version'])
        second = self.ingest(data, 'two')
        self.assertEqual(second['approval_decision_id'], first['approval_decision_id'])
        self.assertEqual(second['version'], 2)
        self.assertEqual(second['events'][0]['payload']['before_group'][0]['person'], 'Synthetic Alpha')
        self.assertEqual(second['events'][0]['payload']['after_group'][0]['person'], 'Synthetic Beta')
        self.assertEqual(self.ingest(frozen), first)
        self.capture(data, 'Synthetic Beta', 20)
        unchanged = self.ingest(data, 'unchanged')
        self.assertEqual(unchanged['events'], [])
        self.assertEqual(unchanged['version'], 3)
        self.assertEqual(unchanged['capture_id'], data['capture_id'])
        self.capture(data, day=21, status=503)
        rejected = self.ingest(data, 'failed-capture')
        self.assertEqual(rejected['disposition'], 'rejected')
        self.assertEqual(rejected['version'], 3)
        self.assertEqual(rejected['baseline_observation_id'], unchanged['observation_id'])
        self.assertEqual(rejected['capture_id'], data['capture_id'])
        self.assertEqual(self.repo.state(data['source'])['baseline'], unchanged['observation'])

    def test_same_config_reapproval_rebaselines_after_a_rejected_capture(self):
        data = self.configured()
        first = self.ingest(data)
        self.capture(data, 'Synthetic Beta', 19)
        self.approve(data)
        self.assertNotEqual(data['approval_decision_id'], first['approval_decision_id'])
        self.assertEqual(data['configuration_id'], first['configuration_id'])
        self.capture(data, day=20, body=b'<html><body>Incomplete directory</body></html>')
        rejected = self.ingest(data, 'rejected-generation')
        self.assertEqual(rejected['disposition'], 'rejected')
        self.assertEqual(rejected['baseline_observation_id'], first['observation_id'])
        self.assertEqual(rejected['version'], 1)
        self.capture(data, 'Synthetic Gamma', 21)
        next_baseline = self.ingest(data, 'new-generation')
        self.assertEqual(next_baseline['disposition'], 'baseline')
        self.assertEqual(next_baseline['events'], [])
        self.assertEqual(next_baseline['version'], 2)
        self.assertEqual(next_baseline['previous_baseline_id'], first['observation_id'])
        self.assertEqual(next_baseline['approval_decision_id'], data['approval_decision_id'])
        self.assertEqual(next_baseline['observation']['revision'], first['observation']['revision'])

    def test_revocation_preserves_exact_receipt_but_rejects_new_or_conflicting_work(self):
        data = self.configured()
        first = self.ingest(data)
        frozen = dict(data)
        with self.connection, self.connection.cursor() as cursor:
            cursor.execute('SELECT id FROM fuentes WHERE id=%s FOR UPDATE', (data['fuente_id'],))
            cursor.execute('UPDATE pep_directory_sources SET approved_decision_id=NULL,current_preview_id=NULL,eligibility_version=eligibility_version+1 '
                           'WHERE id=%s RETURNING eligibility_version', (data['source_id'],))
            data['expected_version'] = cursor.fetchone()[0]
        self.assertEqual(self.ingest(frozen), first)
        self.refused_configured(data, 'new-after-revocation')
        before = self.repo.state(data['source']), self.repo.history(data['source'])
        with self.assertRaisesRegex(ValueError, '^submission conflict$'):
            self.ingest(data)
        self.assertEqual((self.repo.state(data['source']), self.repo.history(data['source'])), before)

    def test_foreign_references_and_invalid_versions_never_supply_authority(self):
        data, foreign = self.configured(), self.configured()
        for key in ('configuration_id', 'capture_id', 'approval_decision_id'):
            with self.subTest(reference=key):
                self.refused_configured(data, **{key: foreign[key]})
        for values in ({'expected_version': True}, {'expected_version': -1}, {'capture_id': 0}, {'approval_decision_id': None}):
            with self.subTest(values=values):
                self.refused_configured(data, **values)
        with self.connection, self.connection.cursor() as cursor:
            cursor.execute('UPDATE pep_directory_sources SET approved_decision_id=NULL WHERE id=%s', (data['source_id'],))
        self.refused_configured(data)

    def test_inactive_or_changed_binding_invalidates_new_work(self):
        for clause in ('activo=false', "url='https://example.invalid/changed'", "tipo='changed'"):
            with self.subTest(clause=clause):
                data = self.configured()
                with self.connection, self.connection.cursor() as cursor:
                    cursor.execute('UPDATE fuentes SET ' + clause + ' WHERE id=%s', (data['fuente_id'],))
                    cursor.execute('SELECT eligibility_version FROM pep_directory_sources WHERE id=%s', (data['source_id'],))
                    data['expected_version'] = cursor.fetchone()[0]
                self.refused_configured(data)

    def test_stored_integrity_mismatches_are_rejected_without_observation_writes(self):
        cases = [
            ('pep_directory_configurations', 'canonical_bytes', b'{}', 'configuration_id'),
            ('pep_directory_configurations', 'binding_url', 'https://example.invalid/other', 'configuration_id'),
            ('pep_directory_captures', 'content_sha256', '0' * 64, 'capture_id'),
            ('pep_directory_captures', 'captured_at', '2026-10-01T00:00:00Z', 'capture_id'),
            ('pep_directory_captures', 'provenance', json.dumps({'approved': True}), 'capture_id'),
            ('pep_directory_previews', 'result_bytes', b'{}', 'preview_id'),
            ('pep_directory_previews', 'result_sha256', '0' * 64, 'preview_id'),
            ('pep_directory_previews', 'wire_payload', json.dumps({'validity': 'valid'}), 'preview_id'),
        ]
        for table, column, value, reference in cases:
            with self.subTest(table=table, column=column):
                data = self.configured()
                with self.connection, self.connection.cursor() as cursor:
                    cursor.execute(f'UPDATE {table} SET {column}=%s WHERE id=%s', (value, data[reference]))
                self.refused_configured(data)

    def test_pending_revocation_blocks_and_then_refuses_configured_work(self):
        import psycopg2
        data = self.configured()
        self.ingest(data)
        before = self.repo.state(data['source']), self.repo.history(data['source'])
        args = {key: data[key] for key in ('configuration_id', 'capture_id', 'approval_decision_id', 'expected_version')}
        ready = Queue()

        def worker():
            connection = psycopg2.connect(host='127.0.0.1', port=5432, dbname=admission.TARGET, user='postgres', connect_timeout=5,
                                          options='-c statement_timeout=5000 -c lock_timeout=5000')
            try:
                with connection, connection.cursor() as cursor:
                    cursor.execute('SELECT current_database(),current_user')
                    if cursor.fetchone() != (admission.TARGET, 'postgres'):
                        raise ValueError('isolated acceptance target mismatch')
                ready.put(connection.get_backend_pid())
                try:
                    admission.Repository(connection).submit_configured(data['source'], submission_id='blocked', **args)
                    return 'ACCEPTED'
                except ValueError as error:
                    return str(error)
            finally:
                connection.close()

        with self.connection.cursor() as cursor, ThreadPoolExecutor(max_workers=1) as pool:
            cursor.execute('SELECT id FROM fuentes WHERE id=%s FOR UPDATE', (data['fuente_id'],))
            cursor.execute('UPDATE pep_directory_sources SET approved_decision_id=NULL,eligibility_version=eligibility_version+1 WHERE id=%s',
                           (data['source_id'],))
            future = pool.submit(worker)
            try:
                pid = ready.get(timeout=2)
                deadline, blocked, pause = monotonic() + 2, False, Event()
                while monotonic() < deadline:
                    cursor.execute('SELECT pg_blocking_pids(%s)', (pid,))
                    if self.connection.get_backend_pid() in cursor.fetchone()[0]:
                        blocked = True
                        break
                    if future.done():
                        break
                    pause.wait(0.01)
                self.assertTrue(blocked, 'Configured ingestion must wait for revocation locks.')
                self.connection.commit()
                self.assertEqual(future.result(timeout=3), 'configured submission refused')
                print('Observed configured-ingestion blocking; refused after revocation commit.')
            finally:
                self.connection.rollback()
        self.assertEqual((self.repo.state(data['source']), self.repo.history(data['source'])), before)

    def test_injected_failure_rolls_back_observation_events_and_baseline(self):
        data = self.configured()
        self.ingest(data)
        self.capture(data, 'Synthetic Beta', 19)
        before = self.repo.state(data['source']), self.repo.history(data['source'])
        connection = self.connection

        class FaultCursor:
            def __init__(self):
                self.cursor = connection.cursor()
            def __enter__(self):
                self.cursor.__enter__()
                return self
            def __exit__(self, *args):
                return self.cursor.__exit__(*args)
            def __getattr__(self, name):
                return getattr(self.cursor, name)
            def execute(self, query, args=None):
                if query.startswith('UPDATE pep_directory_sources SET baseline_observation_id='):
                    raise RuntimeError('injected before baseline update')
                return self.cursor.execute(query, args)

        class FaultConnection:
            def __getattr__(self, name):
                return getattr(connection, name)
            def cursor(self):
                return FaultCursor()

        repo = admission.Repository(FaultConnection())
        args = {key: data[key] for key in ('configuration_id', 'capture_id', 'approval_decision_id', 'expected_version')}
        with self.assertRaisesRegex(RuntimeError, '^injected before baseline update$'):
            repo.submit_configured(data['source'], submission_id='two', **args)
        self.assertEqual((self.repo.state(data['source']), self.repo.history(data['source'])), before)
        self.assertEqual(self.ingest(data, 'two')['version'], 2)
