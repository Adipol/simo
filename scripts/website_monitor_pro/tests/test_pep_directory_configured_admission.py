"""Public Repository checks on the explicitly authorized populated acceptance copy."""
import os
import sys
import unittest
import uuid
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from queue import Queue
from threading import Event
from time import monotonic
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from pep_directory import Validity
from pep_directory_codec import encode_observation
from pep_directory_storage import Repository
if __package__:
    from .test_pep_directory_codec import observation
else:
    from test_pep_directory_codec import observation

TARGET = 'pep_configured_acceptance'
TABLES = {'fuentes', 'users', 'pep_directory_sources', 'pep_directory_observations',
          'pep_directory_events', 'pep_directory_reviews', 'pep_directory_configurations',
          'pep_directory_captures', 'pep_directory_previews', 'pep_directory_configuration_decisions'}


class ConfiguredAdmissionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        opt_in = os.environ.get('PEP_CONFIGURED_ENGINE')
        if opt_in is None:
            raise unittest.SkipTest('explicit PEP_CONFIGURED_ENGINE=pgsql required; no driver or connection')
        if opt_in != 'pgsql':
            raise ValueError('PEP_CONFIGURED_ENGINE must be pgsql')
        import psycopg2
        cls.connection = psycopg2.connect(host='127.0.0.1', port=5432, dbname=TARGET,
                                         user='postgres', connect_timeout=5,
                                         options='-c statement_timeout=5000 -c lock_timeout=5000')
        try:
            with cls.connection, cls.connection.cursor() as cursor:
                cursor.execute('SELECT current_database(), current_user, current_schemas(false)')
                if cursor.fetchone() != (TARGET, 'postgres', ['public']):
                    raise ValueError('isolated acceptance target mismatch')
                cursor.execute("SELECT tablename FROM pg_tables WHERE schemaname='public'")
                if {row[0] for row in cursor.fetchall()} != TABLES:
                    raise ValueError('exact prepared ten-table inventory required')
        except BaseException:
            cls.connection.close()
            raise
        cls.repo = Repository(cls.connection)

    @classmethod
    def tearDownClass(cls):
        cls.connection.close()

    def source(self, configurable=False, fuente_id=None):
        identity = 'synthetic:admission:' + uuid.uuid4().hex
        with self.connection, self.connection.cursor() as cursor:
            if fuente_id is None:
                cursor.execute("INSERT INTO fuentes (label,url,tipo,activo) VALUES (%s,%s,'directorio',true) RETURNING id",
                               (identity, 'https://example.invalid/directory'))
                fuente_id = cursor.fetchone()[0]
            cursor.execute('INSERT INTO pep_directory_sources (identity,fuente_id,configurable) VALUES (%s,%s,%s)',
                           (identity, fuente_id, configurable))
        return replace(observation(), source=identity), fuente_id

    def submit(self, value, submission='one', fuente_id=None, provenance=None):
        return self.repo.submit(value, submission_id=submission, capture_identity='synthetic-capture',
                                fuente_id=fuente_id,
                                provenance={'synthetic': True} if provenance is None else provenance)

    def refused(self, value, **kwargs):
        before = self.repo.state(value.source), self.repo.history(value.source)
        with self.assertRaises(ValueError) as caught:
            self.submit(value, **kwargs)
        self.assertEqual(str(caught.exception), 'configured source requires approved submission')
        self.assertEqual((self.repo.state(value.source), self.repo.history(value.source)), before)

    def test_registered_identity_rejects_caller_claims_without_writes(self):
        value, _ = self.source(configurable=True)
        self.refused(value, provenance={'approved': True, 'validity': 'valid', 'synthetic': True})

    def test_new_and_existing_fuente_aliases_cannot_bypass_registration(self):
        registered, fuente_id = self.source(configurable=True)
        registered_before = self.repo.state(registered.source), self.repo.history(registered.source)
        new_alias = replace(registered, source='synthetic:new-alias:' + uuid.uuid4().hex)
        self.refused(new_alias, fuente_id=fuente_id)
        existing_alias, _ = self.source(fuente_id=fuente_id)
        self.refused(existing_alias)  # Actual persisted binding matters even when caller omits it.
        self.assertEqual((self.repo.state(registered.source), self.repo.history(registered.source)), registered_before)

    def test_exact_historical_retry_survives_registration_but_new_work_does_not(self):
        value, fuente_id = self.source()
        first = self.submit(value)
        self.source(configurable=True, fuente_id=fuente_id)
        self.assertEqual(self.submit(value), first)
        self.refused(replace(value, observed_at=observation(day=2).observed_at), submission='two')
        before = self.repo.state(value.source), self.repo.history(value.source)
        with self.assertRaisesRegex(ValueError, '^submission conflict$'):
            self.submit(value, provenance={'synthetic': False})
        self.assertEqual((self.repo.state(value.source), self.repo.history(value.source)), before)

    def test_registration_lock_blocks_alias_until_committed_registration_is_visible(self):
        import psycopg2
        value, fuente_id = self.source()
        before = self.repo.state(value.source), self.repo.history(value.source)
        ready = Queue()

        def submit_alias():
            connection = psycopg2.connect(host='127.0.0.1', port=5432, dbname=TARGET,
                                          user='postgres', connect_timeout=5,
                                          options='-c statement_timeout=5000 -c lock_timeout=5000')
            try:
                with connection, connection.cursor() as cursor:
                    cursor.execute('SELECT current_database(), current_user')
                    if cursor.fetchone() != (TARGET, 'postgres'):
                        raise ValueError('isolated acceptance target mismatch')
                ready.put(connection.get_backend_pid())
                try:
                    Repository(connection).submit(value, submission_id='concurrent', capture_identity='synthetic-capture',
                                                  provenance={'synthetic': True})
                    return 'ACCEPTED'
                except ValueError as error:
                    return str(error)
            finally:
                connection.close()

        with self.connection.cursor() as cursor, ThreadPoolExecutor(max_workers=1) as pool:
            cursor.execute('SELECT id FROM fuentes WHERE id=%s FOR UPDATE', (fuente_id,))
            cursor.execute('INSERT INTO pep_directory_sources (identity,fuente_id,configurable) VALUES (%s,%s,true)',
                           ('synthetic:concurrent-registration:' + uuid.uuid4().hex, fuente_id))
            future = pool.submit(submit_alias)
            try:
                worker_pid = ready.get(timeout=2)
                deadline = monotonic() + 2
                blocked = False
                pause = Event()
                while monotonic() < deadline:
                    cursor.execute('SELECT pg_blocking_pids(%s)', (worker_pid,))
                    if self.connection.get_backend_pid() in cursor.fetchone()[0]:
                        blocked = True
                        break
                    if future.done():
                        break
                    pause.wait(0.01)
                self.assertTrue(blocked, 'Alias submission must wait for the Fuente registration lock.')
                self.connection.commit()
                self.assertEqual(future.result(timeout=3), 'configured source requires approved submission')
                print('Observed actual Fuente blocking; alias rejected after registration commit.')
            finally:
                self.connection.rollback()  # Release the registration lock even on failed assertions.
        self.assertEqual((self.repo.state(value.source), self.repo.history(value.source)), before)

    def test_nonregistered_legacy_receipts_comparison_retry_and_rejection_are_unchanged(self):
        seed, fuente_id = self.source()
        for binding in (None, fuente_id):
            with self.subTest(binding=binding):
                # Exercise the existing auto-create path, both unbound and explicitly bound.
                self.legacy_history(replace(seed, source=seed.source + ':' + str(binding)), binding)

    def legacy_history(self, value, fuente_id):
        def submit(value, submission='one'):
            return self.submit(value, submission, fuente_id=fuente_id)

        first = submit(value)
        self.assertEqual(first['observation'], encode_observation(value))
        self.assertEqual(first['provenance'], {'synthetic': True})
        self.assertEqual(first['disposition'], 'baseline')
        self.assertEqual(first['version'], 1)
        self.assertEqual(submit(value), first)
        changed = replace(value, observed_at=observation(day=2).observed_at,
                          positions=observation('Beatriz Ejemplo').positions)
        second = submit(changed, 'two')
        self.assertEqual(second['version'], 2)
        self.assertEqual(second['events'][0]['payload']['before_group'][0]['person'], 'Ana Ejemplo')
        self.assertEqual(second['events'][0]['payload']['after_group'][0]['person'], 'Beatriz Ejemplo')
        fresh = replace(changed, observed_at=observation(day=3).observed_at, evidence='fresh evidence')
        third = submit(fresh, 'three')
        self.assertEqual(third['events'], [])
        self.assertEqual(third['version'], 3)
        rejected = submit(replace(fresh, validity=Validity.BLOCKED), 'blocked')
        self.assertEqual(rejected['disposition'], 'rejected')
        self.assertEqual(rejected['version'], 3)
        self.assertEqual(rejected['baseline_observation_id'], third['observation_id'])
        self.assertEqual(self.repo.history(value.source), [first, second, third, rejected])
        self.assertEqual(self.repo.state(value.source)['baseline'], encode_observation(fresh))
        with self.assertRaisesRegex(ValueError, '^submission conflict$'):
            submit(changed)
        self.assertEqual(self.repo.history(value.source), [first, second, third, rejected])


if __name__ == '__main__':
    unittest.main()
