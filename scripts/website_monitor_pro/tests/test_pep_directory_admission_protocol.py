"""DB-free public Repository protocol checks, NOT concurrency/schema acceptance.

A scripted cursor verifies SQL order, parameters and transaction outcomes only.
It cannot establish PostgreSQL locking, rollback durability or schema compatibility.
"""
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from pep_directory_codec import encode_observation
from pep_directory_storage import Repository

if __package__:
    from .test_pep_directory_codec import observation
else:
    from test_pep_directory_codec import observation


class ScriptedCursor:
    def __init__(self, steps):
        self.steps = list(steps)
        self.calls = []
        self.pending = None

    def __enter__(self):
        return self

    def __exit__(self, *args):
        if args[0] is None:
            assert not self.steps, 'unconsumed SQL steps'
            assert self.pending is None, 'unconsumed cursor result'
        return False

    def execute(self, sql, params):
        assert self.pending is None, 'result must be fetched before next execute'
        self.calls.append((sql, params))
        assert self.steps, 'unexpected SQL: ' + sql
        fragment, expected_params, fetch, result = self.steps.pop(0)
        assert fragment in sql, (fragment, sql)
        assert params == expected_params, (params, expected_params)
        self.pending = None if fetch is None else (fetch, result)

    def _fetch(self, kind):
        assert self.pending is not None, 'unexpected fetch'
        expected, result = self.pending
        assert kind == expected, (kind, expected)
        self.pending = None
        return result

    def fetchone(self):
        return self._fetch('one')

    def fetchall(self):
        return self._fetch('all')


class Connection:
    closed = False
    autocommit = False

    def __init__(self, steps):
        self.script = ScriptedCursor(steps)
        self.commits = 0
        self.rollbacks = 0
        self.cursors = 0

    def get_transaction_status(self):
        return 0

    def cursor(self):
        self.cursors += 1
        return self.script

    def commit(self):
        self.commits += 1

    def rollback(self):
        self.rollbacks += 1


def one(fragment, params, result):
    return fragment, params, 'one', result


def admission(value, *, discovered=(9,), current=(7, 9, 'true'), bindings=(9,), registered=True):
    steps = [one('SELECT fuente_id FROM pep_directory_sources', (value.source,), discovered)]
    if bindings:
        steps.append(('SELECT id FROM fuentes WHERE id=ANY(%s) ORDER BY id FOR UPDATE',
                      (list(bindings),), 'all', [(identity,) for identity in bindings]))
    steps.append(one("SELECT id,fuente_id,to_jsonb(s)->>'configurable'", (value.source,), current))
    if bindings:
        steps.append(one('AND to_jsonb(s)', (list(bindings),), (1,) if registered else None))
    return steps


class AdmissionProtocolTest(unittest.TestCase):
    def setUp(self):
        self.value = observation()
        self.kwargs = dict(submission_id='one', capture_identity='synthetic-capture',
                           provenance={'synthetic': True})

    def request(self, **changes):
        return dict(observation=encode_observation(self.value), fuente_id=None,
                    **dict(self.kwargs, **changes))

    def refused(self, steps, message, *, configured=None, **changes):
        connection = Connection(steps)
        repo = Repository(connection)
        with self.assertRaisesRegex(ValueError, '^' + message + '$'):
            if configured is None:
                repo.submit(self.value, **dict(self.kwargs, **changes))
            else:
                repo.submit_configured(self.value.source, **configured)
        self.assertEqual((connection.commits, connection.rollbacks), (0, 1))
        self.assertEqual(connection.script.steps, [])
        self.assertIsNone(connection.script.pending)
        self.assertTrue(all(sql.startswith('SELECT ') for sql, _ in connection.script.calls))
        return connection

    def test_registered_identity_ignores_caller_approval_claims(self):
        steps = admission(self.value)
        steps.append(one('SELECT provenance FROM pep_directory_observations', (7, 'one'), None))
        self.refused(steps, 'configured source requires approved submission',
                     provenance={'approved': True, 'validity': 'valid'})

    def test_new_and_existing_aliases_use_registered_persisted_binding(self):
        for existing in (False, True):
            with self.subTest(existing=existing):
                steps = admission(self.value, discovered=(9,) if existing else None,
                                  current=(7, 9, 'false') if existing else None)
                if existing:
                    steps.append(one('SELECT provenance FROM pep_directory_observations', (7, 'one'), None))
                self.refused(steps, 'configured source requires approved submission',
                             **({} if existing else {'fuente_id': 9}))

    def test_discovered_and_claimed_bindings_lock_in_sorted_order(self):
        steps = admission(self.value, bindings=(3, 9))
        steps.append(one('SELECT provenance FROM pep_directory_observations', (7, 'one'), None))
        self.refused(steps, 'configured source requires approved submission', fuente_id=3)

    def test_changed_discovery_rolls_back_before_alias_query_or_write(self):
        steps = admission(self.value, current=(7, 10, 'false'))[:-1]
        self.refused(steps, 'source binding changed')

    def test_historical_conflict_refused_before_writes(self):
        for changed in ({'provenance': {'synthetic': False}}, {'capture_identity': 'other'},
                        {'fuente_id': 9}):
            with self.subTest(changed=changed):
                steps = admission(self.value)
                steps.append(one('SELECT provenance FROM pep_directory_observations', (7, 'one'),
                                 ({'request': self.request()},)))
                self.refused(steps, 'submission conflict', **changed)

    def test_exact_historical_retry_returns_original_receipt_without_appending(self):
        wire = encode_observation(self.value)
        envelope = dict(request=self.request(), receipt=dict(version=2, previous_baseline_id=40, accepted=True))
        steps = admission(self.value)
        steps.extend([
            one('SELECT provenance FROM pep_directory_observations', (7, 'one'),
                (json.dumps(envelope),)),
            ('INSERT INTO pep_directory_sources', (self.value.source, None), None, None),
            one('WHERE identity=%s FOR UPDATE', (self.value.source,), (7, 99, 8, 9, 'true')),
            one('SELECT id FROM pep_directory_observations', (7, 'one'), (41,)),
            one('SELECT source_id,submission_id,capture_identity', (41,),
                (7, 'one', 'synthetic-capture', hashlib.sha256(self.value.evidence.encode()).hexdigest(),
                 envelope, wire, self.value.observed_at, 'changed', [], datetime(2026, 1, 2, tzinfo=timezone.utc))),
            ('SELECT id,event_index,payload FROM pep_directory_events', (41,), 'all', []),
            one('SELECT provenance FROM pep_directory_observations WHERE id=', (41,), (envelope,)),
        ])
        connection = Connection(steps)
        result = Repository(connection).submit(self.value, **self.kwargs)
        self.assertEqual((connection.commits, connection.rollbacks), (1, 0))
        self.assertEqual(result['observation_id'], 41)
        self.assertEqual(result['version'], 2)  # Frozen receipt, not current source version 8.
        self.assertEqual(result['baseline_observation_id'], 41)
        self.assertEqual(result['previous_baseline_id'], 40)
        self.assertEqual(result['observation'], wire)
        self.assertEqual(result['provenance'], {'synthetic': True})
        self.assertEqual(result['events'], [])
        writes = [sql for sql, _ in connection.script.calls if not sql.startswith('SELECT ')]
        self.assertEqual(len(writes), 1)
        self.assertIn('ON CONFLICT (identity) DO NOTHING', writes[0])

    def test_registration_observed_after_insert_rolls_back_without_capture_write(self):
        steps = admission(self.value, current=(7, 9, 'false'), registered=False)
        steps.extend([
            ('INSERT INTO pep_directory_sources', (self.value.source, None), None, None),
            one('WHERE identity=%s FOR UPDATE', (self.value.source,), (7, None, 0, 9, 'true')),
        ])
        connection = Connection(steps)
        with self.assertRaisesRegex(ValueError, '^configured source requires approved submission$'):
            Repository(connection).submit(self.value, **self.kwargs)
        self.assertEqual((connection.commits, connection.rollbacks), (0, 1))
        self.assertEqual(connection.script.steps, [])
        self.assertFalse(any('INSERT INTO pep_directory_observations' in sql or sql.startswith('UPDATE ')
                             for sql, _ in connection.script.calls))

    def configured(self, **changes):
        return dict(dict(submission_id='configured', configuration_id=11, capture_id=12,
                         approval_decision_id=13, expected_version=2), **changes)

    def test_invalid_configured_references_refused_before_transaction(self):
        for key in ('configuration_id', 'capture_id', 'approval_decision_id', 'expected_version'):
            for invalid in (True, '1', -1, 9223372036854775808):
                with self.subTest(key=key, invalid=invalid):
                    connection = Connection([])
                    with self.assertRaisesRegex(ValueError, '^configured submission refused$'):
                        Repository(connection).submit_configured(self.value.source,
                                                                 **self.configured(**{key: invalid}))
                    self.assertEqual((connection.cursors, connection.commits, connection.rollbacks), (0, 0, 0))

    def test_configured_missing_source_and_mismatched_approval_refused_without_writes(self):
        self.refused([one('SELECT fuente_id FROM pep_directory_sources', (self.value.source,), None)],
                     'configured submission refused', configured=self.configured())
        current = dict(id=7, fuente_id=9, configurable=True, eligibility_version=2,
                       approved_decision_id=99, draft_config_id=11)
        steps = [
            one('SELECT fuente_id FROM pep_directory_sources', (self.value.source,), (9,)),
            one('SELECT to_jsonb(f) FROM fuentes', (9,), ({'activo': True},)),
            one('SELECT to_jsonb(s) FROM pep_directory_sources', (self.value.source,), (current,)),
            one('SELECT id,provenance FROM pep_directory_observations', (7, 'configured'), None),
        ]
        self.refused(steps, 'configured submission refused', configured=self.configured())


if __name__ == '__main__':
    unittest.main()
