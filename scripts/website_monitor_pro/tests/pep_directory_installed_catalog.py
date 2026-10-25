"""Native catalog evidence from a caller-owned connection; never schema approval."""
import json
from pathlib import Path
from typing import ContextManager, Protocol

_SQL = Path(__file__).resolve().parents[3] / 'tests/Fixtures/PepDirectory/schema-contracts/installed-pg17.sql'
_CONTEXT = ("SELECT pg_catalog.current_setting('server_version_num')::integer, "
            "current_user, pg_catalog.current_schemas(false)::text")
_SECTIONS = ('metadata', 'namespaces', 'extension_memberships', 'relations', 'sequences', 'columns', 'constraints', 'indexes',
             'triggers', 'routines', 'policies', 'objects')


class Cursor(Protocol):
    def execute(self, query: str) -> None: ...
    def fetchone(self) -> tuple[object, ...] | None: ...


class Connection(Protocol):
    def cursor(self) -> ContextManager[Cursor]: ...


def collect(connection: Connection) -> dict:
    """Collect fixed PG17/public evidence without owning a transaction or connection.

    The caller must establish its authorized target before calling this helper.
    Cursor protocol tests alone do not establish actual PostgreSQL driver parity.
    """
    with connection.cursor() as cursor:
        cursor.execute(_CONTEXT)
        context = cursor.fetchone()
        if (type(context) is not tuple or len(context) != 3
                or type(context[0]) is not int or not 170000 <= context[0] < 180000
                or context[1:] != ('postgres', '{public}')):
            raise ValueError('Unsupported catalog context.')
        try:
            sql = _SQL.read_text(encoding='utf-8')
        except OSError as error:
            raise ValueError('Fixed catalog projection is unavailable.') from error
        cursor.execute(sql)
        row = cursor.fetchone()
        if type(row) is not tuple or len(row) != 1:
            raise ValueError('Invalid catalog result.')
        catalog = json.loads(row[0]) if type(row[0]) is str else row[0]
        if type(catalog) is not dict or set(catalog) != set(_SECTIONS):
            raise ValueError('Invalid catalog envelope.')
        for section in _SECTIONS:
            entries = catalog[section]
            if section == 'metadata':
                if type(entries) is not dict:
                    raise ValueError('Invalid catalog metadata.')
            elif (type(entries) is not list
                  or any(type(entry) is not dict or type(entry.get('identity')) is not str
                         for entry in entries)):
                raise ValueError('Invalid catalog section: ' + section)
        for entry in catalog['namespaces']:
            if (entry.get('type') != 'schema' or type(entry.get('owner')) is not str
                    or 'acl' not in entry
                    or (entry['acl'] is not None and (type(entry['acl']) is not list
                        or any(type(acl) is not str for acl in entry['acl'])))):
                raise ValueError('Invalid catalog namespace entry.')
        for entry in catalog['extension_memberships']:
            if (entry.get('type') != 'extension membership' or 'member_schema' not in entry
                    or (entry['member_schema'] is not None and type(entry['member_schema']) is not str)
                    or any(type(entry.get(key)) is not str for key in
                           ('extension', 'extension_namespace', 'member_type', 'member_identity'))):
                raise ValueError('Invalid catalog membership entry.')
        metadata = catalog['metadata']
        if (metadata.get('projection') != 'pg17-native-collection-v2'
                or type(metadata.get('server_version_num')) is not int
                or metadata['server_version_num'] != context[0]
                or metadata.get('user') != 'postgres' or metadata.get('schemas') != ['public']):
            raise ValueError('Unsupported catalog metadata.')
        return catalog
