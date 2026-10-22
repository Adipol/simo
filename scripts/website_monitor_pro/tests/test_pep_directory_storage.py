"""Opt-in real PostgreSQL proofs. Ordinary discovery never imports a driver."""
import os
import json
import sys
import threading
import uuid
import unittest
from concurrent.futures import ThreadPoolExecutor
from dataclasses import replace
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from test_pep_directory_codec import observation
from pep_directory import Validity, compare
from pep_directory_codec import encode_observation, encode_event
from pep_directory_storage import Repository

TARGET = 'pep_schema_20261018_d83b91'
IMAGE_OPT_IN = 'PEP_STORAGE_ENGINE'


def connect():
    import psycopg2
    return psycopg2.connect(host='127.0.0.1', port=5432, dbname=TARGET, user='postgres',
                            connect_timeout=5, options='-c statement_timeout=5000 -c lock_timeout=5000')


class StorageTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        engine = os.environ.get(IMAGE_OPT_IN)
        if engine is None:
            raise unittest.SkipTest('explicit PEP_STORAGE_ENGINE=pgsql required; no connection attempted')
        if engine != 'pgsql':
            raise ValueError('PEP_STORAGE_ENGINE must be pgsql')
        cls.connection = connect()
        with cls.connection:
            with cls.connection.cursor() as cursor:
                cursor.execute('SELECT current_database(), current_user')
                if cursor.fetchone() != (TARGET, 'postgres'):
                    raise ValueError('owned target mismatch')
                cursor.execute("SELECT tablename FROM pg_tables WHERE schemaname='public'")
                if {r[0] for r in cursor.fetchall()} != {'users', 'fuentes', 'pep_directory_sources',
                        'pep_directory_observations', 'pep_directory_events', 'pep_directory_reviews'}:
                    raise ValueError('exact prepared inventory required')
        cls.repo = Repository(cls.connection)
        cls.run_identity = uuid.uuid4().hex

    @classmethod
    def tearDownClass(cls):
        cls.connection.close()

    def source(self, suffix=''):
        return replace(observation(), source='synthetic:' + self.run_identity + ':' + self._testMethodName + suffix)

    def submit(self, value, submission='one', repo=None):
        return (repo or self.repo).submit(value, submission_id=submission, capture_identity='synthetic-capture',
                                        provenance={'encoding': 'utf-8', 'raw': [' exact ', None]})

    def test_history_retry_rejections_and_unchanged(self):
        old = self.source()
        first = self.submit(old)
        self.assertEqual(first['observation'], encode_observation(old))
        self.assertEqual(first, self.repo.history(old.source)[0])
        self.assertEqual(first['disposition'], 'baseline')
        self.assertEqual(first['version'], 1)
        changed = replace(old, observed_at=observation(day=2).observed_at, positions=observation('Beatriz Ejemplo').positions)
        second = self.submit(changed, 'two')
        self.assertEqual(second['events'][0]['payload']['before_group'][0]['person'], 'Ana Ejemplo')
        self.assertEqual(second['events'][0]['payload']['after_group'][0]['person'], 'Beatriz Ejemplo')
        self.assertIsNone(second['events'][0]['payload']['effective_date'])
        self.assertEqual(second['observation'], encode_observation(changed))
        self.assertEqual(second['events'][0]['payload'], encode_event(compare(old, changed).events[0]))
        self.assertEqual(self.submit(old), first)
        self.assertEqual(self.submit(changed, 'two'), second)
        before = self.repo.history(old.source)
        with self.assertRaisesRegex(ValueError, 'submission conflict'):
            self.submit(changed)
        self.assertEqual(self.repo.history(old.source), before)
        unchanged = self.submit(replace(changed, observed_at=observation(day=3).observed_at, evidence='fresh raw'), 'three')
        self.assertEqual(unchanged['events'], [])
        self.assertEqual(unchanged['version'], 3)
        for index, invalid in enumerate((old, replace(changed, validity=Validity.BLOCKED),
                                         replace(changed, positions=()))):
            rejected = self.submit(invalid, 'reject-' + str(index))
            self.assertEqual(rejected['observation'], encode_observation(invalid))
            self.assertEqual(rejected['disposition'], 'rejected')
            self.assertEqual(rejected['version'], 3)
            self.assertEqual(rejected['baseline_observation_id'], unchanged['observation_id'])
        self.assertEqual(len(self.repo.history(old.source)), 6)
        self.assertEqual(self.repo.state(old.source)['version'], 3)
        self.assertEqual(self.repo.state(old.source)['baseline'], unchanged['observation'])
        self.assertEqual(second['provenance'], {'encoding': 'utf-8', 'raw': [' exact ', None]})

    def test_request_comparison_is_type_sensitive(self):
        old = self.source()
        first = self.repo.submit(old, submission_id='one', capture_identity='capture', provenance={'flag': 1})
        with self.assertRaisesRegex(ValueError, 'submission conflict'):
            self.repo.submit(old, submission_id='one', capture_identity='capture', provenance={'flag': True})
        self.assertEqual(self.repo.history(old.source), [first])

    def test_malformed_inputs_and_caller_transaction_have_no_effects(self):
        old = self.source()
        for kwargs in ({'submission_id': True}, {'submission_id': 'x' * 129}, {'fuente_id': True},
                       {'provenance': {'bad': float('nan')}}, {'provenance': {'bad': '\ud800'}}, {'capture_identity': ''}):
            options = dict(submission_id='one', capture_identity='capture', provenance={})
            options.update(kwargs)
            with self.assertRaises(ValueError):
                self.repo.submit(old, **options)
        with self.assertRaises(ValueError):
            self.submit(replace(old, positions='bad'))
        self.assertIsNone(self.repo.state(old.source))
        with self.connection.cursor() as cursor:
            cursor.execute('SELECT 1')
        with self.assertRaisesRegex(ValueError, 'idle non-autocommit connection required'):
            self.submit(old)
        self.assertEqual(self.connection.get_transaction_status(), 2)
        self.connection.rollback()
        self.connection.autocommit = True
        try:
            with self.assertRaises(ValueError):
                self.submit(old)
        finally:
            self.connection.autocommit = False
        self.assertIsNone(self.repo.state(old.source))

    def test_revision_boundary_and_full_group_roundtrip(self):
        old = self.source()
        self.submit(old)
        new = replace(old, revision='v2', observed_at=observation(day=2).observed_at)
        result = self.submit(new, 'revision')
        self.assertEqual((result['disposition'], result['reasons'], result['events']),
                         ('rebaselined', ['revision_boundary'], []))
        from pep_directory import Position
        group = replace(new, observed_at=observation(day=3).observed_at,
                        positions=(new.positions[0], Position('Unidad', 'Cargo', 'Carla Ejemplo', None, 'Carla raw')))
        result = self.submit(group, 'group')
        self.assertEqual(result['events'][0]['payload'], encode_event(compare(new, group).events[0]))
        self.assertEqual(result['events'][0]['payload']['kind'], 'membership_change')
        self.assertEqual(len(result['events'][0]['payload']['after_group']), 2)
        self.assertEqual(self.repo.history(old.source)[-1], result)

    def test_fault_rolls_back_counts_baseline_history_and_version(self):
        old = self.source()
        self.submit(old)
        before = self.repo.history(old.source), self.repo.state(old.source), self.counts()
        connection = connect()
        try:
            faulty = Repository(ConnectionWrapper(connection, fail=True))
            with self.assertRaisesRegex(RuntimeError, 'injected after event insert'):
                self.submit(replace(old, observed_at=observation(day=2).observed_at,
                                    positions=observation('Beatriz Ejemplo').positions), 'fault', faulty)
            self.assertEqual((self.repo.history(old.source), self.repo.state(old.source), self.counts()), before)
        finally:
            connection.close()

    def counts(self):
        with self.connection:
            with self.connection.cursor() as cursor:
                cursor.execute('SELECT (SELECT count(*) FROM pep_directory_sources), '
                               '(SELECT count(*) FROM pep_directory_observations), '
                               '(SELECT count(*) FROM pep_directory_events), '
                               '(SELECT count(*) FROM pep_directory_reviews)')
                return cursor.fetchone()

    def test_simultaneous_initial_creation_and_retry(self):
        old = self.source()
        gate = threading.Barrier(2, timeout=5)
        def work():
            connection = connect()
            try:
                gate.wait()
                return self.submit(old, repo=Repository(connection))
            finally:
                connection.close()
        with ThreadPoolExecutor(max_workers=2) as pool:
            futures = [pool.submit(work) for _ in range(2)]
            results = [f.result(timeout=10) for f in futures]
        self.assertEqual(results[0], results[1])
        self.assertEqual(self.repo.history(old.source), [results[0]])
        self.assertEqual(self.repo.state(old.source)['version'], 1)

    def test_concurrent_stale_capture_cannot_overwrite_newer_baseline(self):
        old = self.source()
        self.submit(old)
        locked, release, waiting = threading.Event(), threading.Event(), threading.Event()
        newer = replace(old, observed_at=observation(day=3).observed_at)
        stale = replace(old, observed_at=observation(day=2).observed_at, positions=observation('Beatriz Ejemplo').positions)
        def work(value, submission, hold):
            connection = connect()
            try:
                wrapper = ConnectionWrapper(connection, locked=locked if hold else None,
                                            release=release if hold else None, waiting=None if hold else waiting)
                return self.submit(value, submission, Repository(wrapper))
            finally:
                connection.close()
        with ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(work, newer, 'newer', True)
            self.assertTrue(locked.wait(5))
            second = pool.submit(work, stale, 'stale', False)
            self.assertTrue(waiting.wait(5))
            release.set()
            accepted = first.result(timeout=10)
            rejected = second.result(timeout=10)
        self.assertEqual(rejected['reasons'], ['older_observation'])
        self.assertEqual(rejected['events'], [])
        self.assertEqual(rejected['version'], 2)
        self.assertEqual(self.repo.state(old.source)['baseline'], accepted['observation'])

    def test_waiting_capture_uses_locked_baseline_and_stale_cannot_overwrite(self):
        old = self.source()
        self.submit(old)
        locked, release, waiting = threading.Event(), threading.Event(), threading.Event()
        newer = replace(old, observed_at=observation(day=3).observed_at, positions=observation('Carla Ejemplo').positions)
        later = replace(old, observed_at=observation(day=4).observed_at, positions=observation('Dora Ejemplo').positions)
        def work(value, submission, hold=False):
            connection = connect()
            try:
                wrapper = ConnectionWrapper(connection, locked=locked if hold else None,
                                            release=release if hold else None, waiting=None if hold else waiting)
                return self.submit(value, submission, Repository(wrapper))
            finally:
                connection.close()
        with ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(work, newer, 'newer', True)
            self.assertTrue(locked.wait(5))
            second = pool.submit(work, later, 'later')
            self.assertTrue(waiting.wait(5))
            release.set()
            first.result(timeout=10)
            result = second.result(timeout=10)
        self.assertEqual(result['events'][0]['payload']['before']['person'], 'Carla Ejemplo')
        stale = self.submit(replace(old, observed_at=observation(day=2).observed_at), 'stale')
        self.assertEqual(stale['reasons'], ['older_observation'])
        self.assertEqual(stale['version'], 3)
        self.assertEqual(self.repo.state(old.source)['baseline'], result['observation'])


class ConnectionWrapper:
    """Test-only fault/synchronization seam; delegates real database operations."""
    def __init__(self, connection, fail=False, locked=None, release=None, waiting=None):
        self.connection, self.fail = connection, fail
        self.locked, self.release, self.waiting = locked, release, waiting

    def __getattr__(self, name):
        return getattr(self.connection, name)

    def cursor(self):
        return CursorWrapper(self, self.connection.cursor())


class CursorWrapper:
    def __init__(self, owner, cursor):
        self.owner, self.cursor = owner, cursor

    def __enter__(self):
        self.cursor.__enter__()
        return self

    def __exit__(self, *args):
        return self.cursor.__exit__(*args)

    def __getattr__(self, name):
        return getattr(self.cursor, name)

    def execute(self, sql, params=None):
        if self.owner.waiting and 'FOR UPDATE' in sql:
            self.owner.waiting.set()
        self.cursor.execute(sql, params)
        if self.owner.locked and 'FOR UPDATE' in sql:
            self.owner.locked.set()
            if not self.owner.release.wait(5):
                raise RuntimeError('bounded lock release wait expired')
        if self.owner.fail and 'INSERT INTO pep_directory_events' in sql:
            raise RuntimeError('injected after event insert')
