"""Unused PostgreSQL shadow repository. Caller supplies an exclusively owned connection.

No driver, environment, monitor, logging or network imports. See storage docs for
request/receipt format. Only this module's transactions are committed or rolled back.
"""
import hashlib
import json
from pep_directory import compare, Disposition, Kind
from pep_directory_codec import encode_observation, decode_observation, encode_event, _text


def _json(value):
    """Accept only actual JSON types; never silently coerce keys, tuples or NaN."""
    def check(item):
        if item is None or type(item) in (str, bool, int):
            if type(item) is str and '\x00' in item:
                raise ValueError('invalid provenance')
            return
        if type(item) is float:
            return
        if type(item) is list:
            for child in item:
                check(child)
            return
        if type(item) is dict and all(type(key) is str and '\x00' not in key for key in item):
            for child in item.values():
                check(child)
            return
        raise ValueError('invalid provenance')
    check(value)
    try:
        encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(',', ':'), allow_nan=False)
        encoded.encode('utf-8')  # Reject lone surrogates before opening a transaction.
        return encoded
    except (ValueError, TypeError, UnicodeError) as error:
        raise ValueError('invalid provenance') from error


def _identity(value, length, label):
    _text(value)
    if len(value) > length:
        raise ValueError('invalid ' + label)
    return value


def _loaded(value):
    return json.loads(value) if type(value) is str else value


class Repository:
    def __init__(self, connection):
        self.connection = connection
        self._idle()

    def _idle(self):
        # psycopg2's libpq transaction status IDLE=0; DB-API alone has no safe
        # portable idle predicate. Wrappers must forward this non-mutating API.
        if (self.connection.closed or self.connection.autocommit
                or self.connection.get_transaction_status() != 0):
            raise ValueError('idle non-autocommit connection required')

    def submit(self, observation, *, submission_id, capture_identity, provenance, fuente_id=None):
        """Append one capture and classifications, or return its original receipt."""
        self._idle()
        wire = encode_observation(observation)
        _identity(observation.source, 255, 'source identity')
        _identity(submission_id, 128, 'submission identity')
        _identity(capture_identity, 255, 'capture identity')
        if type(provenance) is not dict:
            raise ValueError('invalid provenance')
        if fuente_id is not None and (type(fuente_id) is not int or not 0 < fuente_id <= 9223372036854775807):
            raise ValueError('invalid fuente_id')
        request = dict(observation=wire, submission_id=submission_id, capture_identity=capture_identity,
                       provenance=provenance, fuente_id=fuente_id)
        # Freeze caller-owned dictionaries before any transaction or blocking lock.
        request = json.loads(_json(request))
        try:
            with self.connection.cursor() as cursor:
                binding, historical = self._legacy_admission(cursor, observation.source, fuente_id, request)
                cursor.execute('INSERT INTO pep_directory_sources (identity, fuente_id) VALUES (%s,%s) '
                               'ON CONFLICT (identity) DO NOTHING', (observation.source, fuente_id))
                cursor.execute("SELECT id, baseline_observation_id, version, fuente_id, "
                               "to_jsonb(s)->>'configurable' FROM pep_directory_sources s "
                               'WHERE identity=%s FOR UPDATE', (observation.source,))
                source_id, baseline_id, version, actual_binding, configurable = cursor.fetchone()
                if actual_binding != binding:
                    raise ValueError('source binding changed')
                # A concurrent registration may have won the unique identity insert.
                if configurable == 'true' and not historical:
                    raise ValueError('configured source requires approved submission')
                cursor.execute('SELECT id FROM pep_directory_observations WHERE source_id=%s AND submission_id=%s',
                               (source_id, submission_id))
                existing = cursor.fetchone()
                if existing:
                    result = self._receipt(cursor, existing[0])
                    cursor.execute('SELECT provenance FROM pep_directory_observations WHERE id=%s', (existing[0],))
                    if _json(_loaded(cursor.fetchone()[0])['request']) != _json(request):
                        raise ValueError('submission conflict')
                else:
                    baseline = None
                    if baseline_id is not None:
                        cursor.execute('SELECT payload FROM pep_directory_observations WHERE id=%s', (baseline_id,))
                        baseline = decode_observation(_loaded(cursor.fetchone()[0]))
                    comparison = compare(baseline, decode_observation(request['observation']))
                    accepted = comparison.disposition != Disposition.REJECTED
                    next_version = version + int(accepted)
                    envelope = dict(codec='pep-directory-v1', request=request,
                                    receipt=dict(version=next_version, previous_baseline_id=baseline_id,
                                                 accepted=accepted))
                    cursor.execute('INSERT INTO pep_directory_observations '
                                   '(source_id,submission_id,capture_identity,content_sha256,provenance,payload,observed_at,disposition,reasons) '
                                   'VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id',
                                   (source_id, submission_id, capture_identity,
                                    hashlib.sha256(observation.evidence.encode('utf-8')).hexdigest(),
                                    _json(envelope), _json(wire), wire['observed_at'], comparison.disposition.value,
                                    _json(list(comparison.reasons))))
                    observation_id = cursor.fetchone()[0]
                    for index, event in enumerate(comparison.events):
                        if event.kind == Kind.NO_CHANGE:
                            continue
                        cursor.execute('INSERT INTO pep_directory_events '
                                       '(source_id,previous_observation_id,current_observation_id,event_index,payload) '
                                       'VALUES (%s,%s,%s,%s,%s)',
                                       (source_id, baseline_id, observation_id, index, _json(encode_event(event))))
                    if accepted:
                        cursor.execute('UPDATE pep_directory_sources SET baseline_observation_id=%s, version=%s WHERE id=%s',
                                       (observation_id, next_version, source_id))
                    result = self._receipt(cursor, observation_id)
            self.connection.commit()
            return result
        except BaseException:
            self.connection.rollback()
            raise

    def submit_configured(self, source, *, submission_id, configuration_id, capture_id,
                          approval_decision_id, expected_version):
        """Ingest a stored capture using its persisted configuration/approval authority."""
        # Keep the legacy import path independent of configurable parser dependencies.
        from pep_directory_configured_storage import submit
        return submit(self, source, submission_id=submission_id, configuration_id=configuration_id,
                      capture_id=capture_id, approval_decision_id=approval_decision_id, expected_version=expected_version)

    def _legacy_admission(self, cursor, source, fuente_id, request):
        """Serialize known bindings with registration; caller flags never authorize work.

        JSON projection keeps the retained six-table/V1 schema usable. Never take
        another Fuente lock after locking a source; a changed discovery is refused.
        """
        cursor.execute('SELECT fuente_id FROM pep_directory_sources WHERE identity=%s', (source,))
        discovered = cursor.fetchone()
        binding = discovered[0] if discovered else fuente_id
        bindings = sorted({value for value in (binding, fuente_id) if value is not None})
        if bindings:
            cursor.execute('SELECT id FROM fuentes WHERE id=ANY(%s) ORDER BY id FOR UPDATE', (bindings,))
            cursor.fetchall()
        cursor.execute("SELECT id,fuente_id,to_jsonb(s)->>'configurable' FROM pep_directory_sources s "
                       'WHERE identity=%s FOR UPDATE', (source,))
        current = cursor.fetchone()
        if current and current[1] != binding:
            raise ValueError('source binding changed')
        blocked = current is not None and current[2] == 'true'
        if bindings:
            cursor.execute("SELECT 1 FROM pep_directory_sources s WHERE fuente_id=ANY(%s) "
                           "AND to_jsonb(s)->>'configurable'='true' LIMIT 1", (bindings,))
            registered_binding = cursor.fetchone() is not None
            blocked = blocked or registered_binding
        if not blocked:
            return binding, False
        if current:
            cursor.execute('SELECT provenance FROM pep_directory_observations WHERE source_id=%s AND submission_id=%s',
                           (current[0], request['submission_id']))
            existing = cursor.fetchone()
            if existing:
                if _json(_loaded(existing[0])['request']) != _json(request):
                    raise ValueError('submission conflict')
                # Historical receipt only: no eligibility restoration or baseline update.
                return binding, True
        raise ValueError('configured source requires approved submission')

    def _receipt(self, cursor, observation_id):
        cursor.execute('SELECT source_id,submission_id,capture_identity,content_sha256,provenance,payload,'
                       'observed_at,disposition,reasons,received_at FROM pep_directory_observations WHERE id=%s',
                       (observation_id,))
        row = cursor.fetchone()
        envelope = _loaded(row[4])
        metadata = envelope['receipt']
        cursor.execute('SELECT id,event_index,payload FROM pep_directory_events WHERE current_observation_id=%s '
                       'ORDER BY event_index', (observation_id,))
        events = [dict(event_id=e[0], event_index=e[1], payload=_loaded(e[2])) for e in cursor.fetchall()]
        result = dict(observation_id=observation_id, source_id=row[0], submission_id=row[1], capture_identity=row[2],
                    content_sha256=row[3], provenance=envelope['request']['provenance'], observation=_loaded(row[5]),
                    observed_at=row[6], disposition=row[7], reasons=_loaded(row[8]), received_at=row[9].isoformat(),
                    version=metadata['version'], baseline_observation_id=(observation_id if metadata['accepted']
                    else metadata['previous_baseline_id']), previous_baseline_id=metadata['previous_baseline_id'], events=events)
        if 'lineage' in metadata:
            cursor.execute('SELECT configuration_id,capture_id,approval_decision_id FROM pep_directory_observations WHERE id=%s',
                           (observation_id,))
            lineage = dict(zip(('configuration_id', 'capture_id', 'approval_decision_id'), cursor.fetchone()))
            if _json(lineage) != _json(metadata['lineage']):
                raise ValueError('configured receipt lineage mismatch')
            result.update(lineage)
        return result

    def history(self, source):
        """Original immutable receipts ordered by append ID, including rejected captures."""
        self._idle()
        _identity(source, 255, 'source identity')
        try:
            with self.connection.cursor() as cursor:
                cursor.execute('SELECT o.id FROM pep_directory_observations o JOIN pep_directory_sources s '
                               'ON s.id=o.source_id WHERE s.identity=%s ORDER BY o.id', (source,))
                ids = [row[0] for row in cursor.fetchall()]
                result = [self._receipt(cursor, identity) for identity in ids]
            self.connection.commit()
            return result
        except BaseException:
            self.connection.rollback()
            raise

    def state(self, source):
        """Current baseline and source version, or None; not a tenure assertion."""
        self._idle()
        _identity(source, 255, 'source identity')
        try:
            with self.connection.cursor() as cursor:
                cursor.execute('SELECT s.id,s.version,s.baseline_observation_id,o.payload FROM pep_directory_sources s '
                               'LEFT JOIN pep_directory_observations o ON o.id=s.baseline_observation_id '
                               'WHERE s.identity=%s', (source,))
                row = cursor.fetchone()
                result = None if row is None else dict(source_id=row[0], version=row[1], baseline_observation_id=row[2],
                                                       baseline=_loaded(row[3]))
            self.connection.commit()
            return result
        except BaseException:
            self.connection.rollback()
            raise
