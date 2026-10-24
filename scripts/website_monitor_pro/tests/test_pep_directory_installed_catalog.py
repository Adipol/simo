"""Offline cursor-protocol checks; these do not connect to PostgreSQL."""
import copy
import json
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

from pep_directory_installed_catalog import collect

SQL = Path(__file__).resolve().parents[3] / 'tests/Fixtures/PepDirectory/schema-contracts/installed-pg17.sql'


def snapshot():
    value = {name: [] for name in ('relations', 'sequences', 'columns', 'constraints',
                                  'indexes', 'triggers', 'routines', 'policies', 'objects')}
    value['metadata'] = {'projection': 'pg17-native-collection-v1',
                         'server_version_num': 170009, 'user': 'postgres', 'schemas': ['public']}
    value['relations'] = [{'identity': 'public.synthetic', 'flag': True, 'missing': None,
                           'ordered': [2, 1], 'definition': "CHECK ((version >= '0'::bigint))"}]
    return value


def connection(payload=None, context=(170009, 'postgres', '{public}')):
    conn = MagicMock()
    cursor = conn.cursor.return_value.__enter__.return_value
    cursor.fetchone.side_effect = [context, (payload,)]
    return conn, cursor


class InstalledCatalogProtocolTest(unittest.TestCase):
    def test_collect_preserves_native_values_and_uses_fixed_sql_without_owning_connection(self):
        expected = snapshot()
        for payload in (expected, json.dumps(expected)):
            with self.subTest(serialized=isinstance(payload, str)):
                before = copy.deepcopy(expected)
                conn, cursor = connection(payload)
                actual = collect(conn)
                self.assertEqual(before, actual)
                self.assertIs(actual['relations'][0]['flag'], True)
                self.assertIsNone(actual['relations'][0]['missing'])
                self.assertIs(type(actual['metadata']['server_version_num']), int)
                self.assertEqual(expected, before)
                self.assertEqual(cursor.execute.call_count, 2)
                self.assertEqual(cursor.execute.call_args_list[1].args, (SQL.read_text(encoding='utf-8'),))
                conn.cursor.return_value.__exit__.assert_called_once()
                conn.commit.assert_not_called()
                conn.rollback.assert_not_called()
                conn.close.assert_not_called()

    def test_invalid_context_stops_before_projection_and_closes_cursor(self):
        contexts = [None, (), (170009,), (170009, 'postgres', '{public}', 'extra'),
                    (True, 'postgres', '{public}'), (160000, 'postgres', '{public}'),
                    (180000, 'postgres', '{public}'), ('170009', 'postgres', '{public}'),
                    (170009, 'other', '{public}'), (170009, 'postgres', '{public,other}')]
        for context in contexts:
            with self.subTest(context=context):
                conn, cursor = connection(snapshot(), context)
                with patch('pathlib.Path.read_text') as read_sql:
                    with self.assertRaisesRegex(ValueError, 'Unsupported catalog context'):
                        collect(conn)
                    read_sql.assert_not_called()
                self.assertEqual(cursor.execute.call_count, 1)
                conn.cursor.return_value.__exit__.assert_called_once()
                conn.commit.assert_not_called()
                conn.rollback.assert_not_called()

    def test_malformed_envelopes_are_rejected_without_mutation(self):
        cases = [None, [], '{']
        for change in ('missing', 'extra', 'list', 'entry', 'identity', 'metadata', 'projection', 'version', 'actor', 'schemas'):
            value = snapshot()
            if change == 'missing':
                del value['policies']
            elif change == 'extra':
                value['unexpected'] = []
            elif change == 'list':
                value['relations'] = {}
            elif change == 'entry':
                value['relations'] = [None]
            elif change == 'identity':
                value['relations'] = [{'identity': 1}]
            elif change == 'metadata':
                value['metadata'] = []
            elif change == 'projection':
                value['metadata']['projection'] = 'unknown'
            elif change == 'version':
                value['metadata']['server_version_num'] = '170009'
            elif change == 'actor':
                value['metadata']['user'] = 'other'
            else:
                value['metadata']['schemas'] = ['public', 'other']
            cases.append(value)
        for payload in cases:
            with self.subTest(payload=payload):
                before = copy.deepcopy(payload)
                conn, cursor = connection(payload)
                with self.assertRaises(ValueError):
                    collect(conn)
                self.assertEqual(payload, before)
                self.assertEqual(cursor.execute.call_count, 2)
                conn.cursor.return_value.__exit__.assert_called_once()
                conn.commit.assert_not_called()
                conn.rollback.assert_not_called()

    def test_invalid_result_rows_are_rejected_and_cursor_is_closed(self):
        for row in (None, (), [], (snapshot(), 'extra')):
            with self.subTest(row=row):
                conn, cursor = connection()
                cursor.fetchone.side_effect = [(170009, 'postgres', '{public}'), row]
                with self.assertRaisesRegex(ValueError, 'Invalid catalog result'):
                    collect(conn)
                self.assertEqual(cursor.execute.call_count, 2)
                conn.cursor.return_value.__exit__.assert_called_once()
                conn.commit.assert_not_called()
                conn.rollback.assert_not_called()

    def test_unavailable_fixed_projection_stops_before_second_query(self):
        conn, cursor = connection(snapshot())
        with patch('pathlib.Path.read_text', side_effect=OSError('synthetic missing file')):
            with self.assertRaisesRegex(ValueError, 'Fixed catalog projection is unavailable'):
                collect(conn)
        self.assertEqual(cursor.execute.call_count, 1)
        conn.cursor.return_value.__exit__.assert_called_once()

    def test_caller_cannot_supply_sql_or_manifest(self):
        conn, cursor = connection(snapshot())
        with self.assertRaises(TypeError):
            collect(conn, 'SELECT caller_override')
        conn.cursor.assert_not_called()
        cursor.execute.assert_not_called()


if __name__ == '__main__':
    unittest.main()
