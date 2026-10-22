"""Default-off static capture observer. No environment reads or connections at import.

Bindings are operator attestations, not independent completeness proof. The monitor
owns error containment; this module raises on invalid configuration/provenance.
"""
import codecs
from dataclasses import dataclass, replace
from datetime import datetime, timezone
from email.message import Message
import hashlib
import json
from pathlib import Path
from urllib.parse import urlsplit
from uuid import uuid4

from pep_directory_extractors import Capture, extract, _URLS
from pep_directory_storage import Repository


@dataclass(frozen=True)
class FrozenCapture:
    body: bytes
    fuente_id: int
    requested_url: str
    final_url: str
    status: int
    encoding: str
    charset_provenance: str
    observed_at: datetime


def freeze_response(response, fuente):
    """Only an explicitly declared HTTP charset is evidence; never apparent_encoding."""
    header = Message()
    header['content-type'] = response.headers.get('Content-Type', '')
    charsets = [value for key, value in header.get_params()[1:] if key.lower() == 'charset']
    if len(charsets) != 1 or not isinstance(charsets[0], str) or not charsets[0].strip():
        raise ValueError('declared charset required')
    encoding = codecs.lookup(charsets[0]).name
    return FrozenCapture(bytes(response.content), fuente['id'], fuente['url'], response.url,
                         response.status_code, encoding, 'content-type', datetime.now(timezone.utc))


def _text(value):
    if type(value) is not str or not value.strip() or '\x00' in value or len(value) > 255:
        raise ValueError('invalid attestation')
    value.encode('utf-8')
    return value


def _url(value):
    _text(value)
    parts = urlsplit(value)
    if parts.scheme not in ('http', 'https') or not parts.hostname or parts.username or parts.password or parts.fragment:
        raise ValueError('invalid binding URL')
    return value


def _unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('duplicate configuration key')
        result[key] = value
    return result


def load_observer(path):
    """Called only after explicit environment selection, never from the default path."""
    with Path(path).open(encoding='utf-8') as stream:
        configuration = json.load(stream, object_pairs_hook=_unique_object)
    return Observer(configuration)


class Observer:
    def __init__(self, configuration, *, connect=None, repository=Repository):
        if type(configuration) is not dict or set(configuration) != {'bindings', 'connection'}:
            raise ValueError('invalid shadow configuration')
        connection = configuration['connection']
        # Explicit libpq parameters only: no legacy DB_* or libpq service/DSN fallback.
        if type(connection) is not dict or set(connection) != {'host', 'port', 'dbname', 'user', 'password'}:
            raise ValueError('explicit connection required')
        for key in ('host', 'dbname', 'user'):
            _text(connection[key])
        if (type(connection['password']) is not str or '\x00' in connection['password']
                or type(connection['port']) is not int or not 0 < connection['port'] <= 65535):
            raise ValueError('invalid connection')
        self.connection = dict(connection, connect_timeout=5,
                               options='-c statement_timeout=5000 -c lock_timeout=2000')
        bindings = configuration['bindings']
        if type(bindings) is not list or not bindings:
            raise ValueError('nonempty bindings required')
        self.bindings = {}
        sources, urls = set(), set()
        keys = {'fuente_id', 'requested_url', 'source', 'profile', 'accepted_final_url',
                'expected_positions', 'reviewer', 'evidence_identity', 'inventory_revision'}
        for binding in bindings:
            if type(binding) is not dict or set(binding) != keys:
                raise ValueError('invalid binding')
            fid = binding['fuente_id']
            if type(fid) is not int or not 0 < fid <= 9223372036854775807 or fid in self.bindings:
                raise ValueError('duplicate or invalid source')
            for key in ('source', 'reviewer', 'evidence_identity', 'inventory_revision'):
                _text(binding[key])
            _url(binding['requested_url'])
            _url(binding['accepted_final_url'])
            if binding['profile'] not in _URLS or binding['accepted_final_url'] != _URLS[binding['profile']]:
                raise ValueError('unsupported qualified profile URL')
            pairs = binding['expected_positions']
            if type(pairs) is not list or not pairs:
                raise ValueError('explicit inventory required')
            for pair in pairs:
                if type(pair) is not list or len(pair) != 2:
                    raise ValueError('invalid inventory')
                for value in pair:
                    _text(value)
            inventory = frozenset(tuple(pair) for pair in pairs)
            if len(inventory) != len(pairs) or binding['source'] in sources or binding['requested_url'] in urls:
                raise ValueError('duplicate or conflicting binding')
            sources.add(binding['source'])
            urls.add(binding['requested_url'])
            # Canonical set order: reordered identical inventory is the same qualification.
            attestation = dict(binding, expected_positions=sorted(inventory))
            fingerprint = hashlib.sha256(json.dumps(attestation, sort_keys=True, ensure_ascii=False,
                                                    separators=(',', ':')).encode()).hexdigest()
            self.bindings[fid] = (json.loads(json.dumps(attestation)), inventory, fingerprint)
        self.connect = connect
        self.repository = repository

    def __call__(self, capture):
        if not isinstance(capture, FrozenCapture) or type(capture.fuente_id) is not int:
            raise ValueError('invalid capture')
        qualified = self.bindings.get(capture.fuente_id)
        if qualified is None:
            raise ValueError('unbound source')
        binding, inventory, fingerprint = qualified
        if (capture.requested_url != binding['requested_url']
                or capture.final_url != binding['accepted_final_url']
                or capture.charset_provenance != 'content-type' or type(capture.body) is not bytes
                or type(capture.status) is not int or not isinstance(capture.observed_at, datetime)
                or capture.observed_at.utcoffset() is None or not capture.encoding):
            raise ValueError('unqualified capture')
        try:
            codecs.lookup(capture.encoding)
        except (LookupError, TypeError) as error:
            raise ValueError('invalid declared charset') from error
        observation = extract(binding['profile'], capture.body,
                              Capture(capture.status, capture.final_url, capture.observed_at, inventory, capture.encoding))
        observation = replace(observation, source=binding['source'],
                              revision=f'{observation.revision}:{binding["inventory_revision"]}:{fingerprint}')
        # Each owned operation has a new submission identity, even for identical bodies.
        # No retries: a future retry must retain this entire immutable request, not recapture.
        submission_id = uuid4().hex
        provenance = dict(binding=binding, requested_url=capture.requested_url, final_url=capture.final_url,
                          status=capture.status, encoding=capture.encoding,
                          charset_provenance=capture.charset_provenance,
                          body_sha256=hashlib.sha256(capture.body).hexdigest(), qualification=fingerprint)
        connect = self.connect
        if connect is None:
            import psycopg2
            connect = psycopg2.connect
        connection = connect(**self.connection)
        try:
            return self.repository(connection).submit(observation, submission_id=submission_id,
                    capture_identity='static-http:' + submission_id, provenance=provenance, fuente_id=capture.fuente_id)
        finally:
            connection.close()
