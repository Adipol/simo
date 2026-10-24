"""Dormant persisted-only ingestion. No transport, actor reauthorization or driver."""
import base64
import codecs
from datetime import datetime
from hashlib import sha256
import re

from pep_directory import compare, Disposition, Kind
from pep_directory_codec import decode_observation, encode_observation, encode_event
from pep_directory_configurable import DirectoryConfig, extract_configured
from pep_directory_extractors import Capture
from pep_directory_preview import compile_preview
from pep_directory_storage import _identity, _json, _loaded


def _need(condition):
    if not condition:
        raise ValueError('configured submission refused')


def _row(cursor, table, identity, source_id):
    # Table identifiers below are module-owned constants, never request input.
    cursor.execute(f'SELECT to_jsonb(t) FROM {table} t WHERE id=%s AND source_id=%s', (identity, source_id))
    row = cursor.fetchone()
    _need(row is not None)
    return row[0]


def _bytes(value, limit):
    _need(type(value) is str and value.startswith('\\x') and len(value) <= limit * 2 + 2)
    try:
        return bytes.fromhex(value[2:])
    except ValueError:
        raise ValueError('configured submission refused') from None


def _capture(row, url, inventory):
    body = _bytes(row['body_bytes'], 1_048_576)
    metadata, provenance = row['metadata'], row['provenance']
    _need(sha256(body).hexdigest() == row['content_sha256'])
    _need(type(metadata) is dict and set(metadata) == {'status', 'source_url', 'observed_at', 'encoding'})
    _need(type(provenance) is dict and provenance.get('requested_url') == url and provenance.get('final_url') == url)
    _need(type(metadata['status']) is int and 100 <= metadata['status'] <= 599 and metadata['source_url'] == url)
    _need(type(metadata['observed_at']) is str and len(metadata['observed_at']) <= 64)
    _need(type(metadata['encoding']) is str and re.fullmatch(r'[A-Za-z0-9_.-]{1,64}', metadata['encoding']) is not None)
    try:
        observed = datetime.fromisoformat(metadata['observed_at'])
        stored = datetime.fromisoformat(row['captured_at'])
        _need(observed.utcoffset() is not None and observed == stored)
        _need(codecs.lookup(metadata['encoding'])._is_text_encoding)
    except (ValueError, LookupError):
        raise ValueError('configured submission refused') from None
    return body, Capture(metadata['status'], url, observed, inventory, metadata['encoding'])


def _approved(cursor, source, fuente, request):
    _need(source.get('configurable') is True and fuente is not None and fuente.get('activo') is True)
    _need(source['eligibility_version'] == request['expected_version'])
    _need(source['approved_decision_id'] == request['approval_decision_id']
          and source['draft_config_id'] == request['configuration_id'])
    identity = source['id']
    approval = _row(cursor, 'pep_directory_configuration_decisions', request['approval_decision_id'], identity)
    _need(approval['action'] == 'approve' and approval['actor_kind'] == 'human'
          and approval['new_approval_id'] == approval['id']
          and approval['configuration_id'] == request['configuration_id']
          and approval['preview_id'] is not None and approval['preview_id'] == source['current_preview_id'])
    preview = _row(cursor, 'pep_directory_previews', approval['preview_id'], identity)
    _need(preview['configuration_id'] == request['configuration_id']
          and preview['eligibility_version'] + 1 == approval['sequence']
          and approval['sequence'] <= source['eligibility_version'])
    config = _row(cursor, 'pep_directory_configurations', request['configuration_id'], identity)
    _need(config['binding_url'] == fuente['url'] == config['directory_url'])
    approved_capture = _row(cursor, 'pep_directory_captures', preview['capture_id'], identity)
    body, _ = _capture(approved_capture, fuente['url'], frozenset())
    try:
        replay = compile_preview(dict(declaration=config['declaration'], body_base64=base64.b64encode(body).decode(),
                                      metadata=approved_capture['metadata']))
    except ValueError:
        raise ValueError('configured submission refused') from None
    canonical, result = replay['configuration'], replay['preview']
    _need(config['declaration']['source'] == source['identity'] and config['declaration']['url'] == fuente['url'])
    _need(config['revision'] == canonical['revision']
          and _bytes(config['canonical_bytes'], 2_097_152) == base64.b64decode(canonical['canonical_base64']))
    _need(_bytes(preview['result_bytes'], 8_388_608) == base64.b64decode(result['result_base64'])
          and preview['result_sha256'] == result['result_sha256']
          and _json(preview['wire_payload']) == _json(result['wire']) and preview['validity'] == result['validity'])
    positions = result['wire']['positions']
    _need(result['validity'] in ('valid', 'incomplete') and bool(positions)
          and all(type(p['person']) is str and p['person'].strip() for p in positions))
    # Replay is deliberately unreviewed. Only the persisted approval authorizes this inventory.
    inventory = frozenset((p['unit'], p['role']) for p in positions)
    return DirectoryConfig.from_dict(config['declaration']), inventory


def _append(repo, cursor, source, fuente, request):
    config, inventory = _approved(cursor, source, fuente, request)
    capture = _row(cursor, 'pep_directory_captures', request['capture_id'], source['id'])
    body, metadata = _capture(capture, fuente['url'], inventory)
    observation = extract_configured(config, body, metadata)
    wire = encode_observation(observation)
    baseline_id, baseline = source['baseline_observation_id'], None
    if baseline_id is not None:
        cursor.execute('SELECT payload,approval_decision_id FROM pep_directory_observations WHERE id=%s AND source_id=%s',
                       (baseline_id, source['id']))
        previous = cursor.fetchone()
        _need(previous is not None)
        if previous[1] == request['approval_decision_id']:
            baseline = decode_observation(_loaded(previous[0]))
    comparison = compare(baseline, observation)
    accepted = comparison.disposition != Disposition.REJECTED
    version = source['version'] + int(accepted)
    lineage = {key: request[key] for key in ('configuration_id', 'capture_id', 'approval_decision_id')}
    envelope = dict(codec='pep-directory-v1',
                    request=dict(configured_request=request, provenance=capture['provenance']),
                    receipt=dict(version=version, previous_baseline_id=baseline_id, accepted=accepted, lineage=lineage))
    cursor.execute('INSERT INTO pep_directory_observations '
                   '(source_id,submission_id,capture_identity,content_sha256,provenance,payload,observed_at,disposition,reasons,'
                   'configuration_id,capture_id,approval_decision_id) VALUES (%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s) RETURNING id',
                   (source['id'], request['submission_id'], 'stored-capture:' + str(request['capture_id']),
                    capture['content_sha256'], _json(envelope), _json(wire), wire['observed_at'], comparison.disposition.value,
                    _json(list(comparison.reasons)), request['configuration_id'], request['capture_id'], request['approval_decision_id']))
    observation_id = cursor.fetchone()[0]
    for index, event in enumerate(comparison.events):
        if event.kind != Kind.NO_CHANGE:
            cursor.execute('INSERT INTO pep_directory_events (source_id,previous_observation_id,current_observation_id,event_index,payload) '
                           'VALUES (%s,%s,%s,%s,%s)', (source['id'], baseline_id, observation_id, index, _json(encode_event(event))))
    if accepted:
        cursor.execute('UPDATE pep_directory_sources SET baseline_observation_id=%s,version=%s WHERE id=%s',
                       (observation_id, version, source['id']))
    return repo._receipt(cursor, observation_id)


def submit(repo, source, *, submission_id, configuration_id, capture_id, approval_decision_id, expected_version):
    repo._idle()
    _identity(source, 255, 'source identity')
    _identity(submission_id, 128, 'submission identity')
    _need(all(type(value) is int and 0 < value <= 9223372036854775807
              for value in (configuration_id, capture_id, approval_decision_id)))
    _need(type(expected_version) is int and 0 <= expected_version <= 9223372036854775807)
    request = dict(source=source, submission_id=submission_id, configuration_id=configuration_id,
                   capture_id=capture_id, approval_decision_id=approval_decision_id, expected_version=expected_version)
    try:
        with repo.connection.cursor() as cursor:
            cursor.execute('SELECT fuente_id FROM pep_directory_sources WHERE identity=%s', (source,))
            discovered = cursor.fetchone()
            _need(discovered is not None)
            binding, fuente = discovered[0], None
            if binding is not None:
                cursor.execute('SELECT to_jsonb(f) FROM fuentes f WHERE id=%s FOR UPDATE', (binding,))
                row = cursor.fetchone()
                fuente = row[0] if row else None
            cursor.execute('SELECT to_jsonb(s) FROM pep_directory_sources s WHERE identity=%s FOR UPDATE', (source,))
            row = cursor.fetchone()
            _need(row is not None and row[0]['fuente_id'] == binding)
            current = row[0]
            cursor.execute('SELECT id,provenance FROM pep_directory_observations WHERE source_id=%s AND submission_id=%s',
                           (current['id'], submission_id))
            existing = cursor.fetchone()
            if existing:
                frozen = _loaded(existing[1])['request'].get('configured_request')
                if frozen is None or _json(frozen) != _json(request):
                    raise ValueError('submission conflict')
                result = repo._receipt(cursor, existing[0])
            else:
                result = _append(repo, cursor, current, fuente, request)
        repo.connection.commit()
        return result
    except BaseException:
        repo.connection.rollback()
        raise
