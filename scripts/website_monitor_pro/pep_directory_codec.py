"""Explicit lossless v1 wire codec. No I/O, qualification or comparison rules."""
from datetime import datetime
from pep_directory import Observation, Position, Event, Validity, Kind


def _fail():
    raise ValueError('invalid directory wire value')


def _object(value, keys):
    if type(value) is not dict or set(value) != set(keys):
        _fail()
    return value


def _text(value, nullable=False, blank=False):
    if nullable and value is None:
        return value
    if type(value) is not str or '\x00' in value or (not blank and not value.strip()):
        _fail()
    return value


def _date(value):
    _text(value)
    try:
        date = datetime.fromisoformat(value)
        if date.utcoffset() is None or date.isoformat() != value:
            _fail()
        return date
    except (ValueError, TypeError):
        _fail()


def _array(value, decoder):
    if type(value) is not list:
        _fail()
    return tuple(decoder(item) for item in value)


def _position(value):
    _object(value, ('unit', 'role', 'person', 'condition', 'evidence'))
    return Position(_text(value['unit']), _text(value['role']),
                    _text(value['person'], nullable=True), _text(value['condition'], nullable=True),
                    _text(value['evidence'], blank=True))


def _position_wire(value):
    if type(value) is not Position:
        _fail()
    return {key: getattr(value, key) for key in ('unit', 'role', 'person', 'condition', 'evidence')}


def decode_observation(value):
    _object(value, ('source', 'revision', 'observed_at', 'validity', 'positions', 'evidence'))
    try:
        validity = Validity(_text(value['validity']))
    except ValueError:
        _fail()
    return Observation(_text(value['source']), _text(value['revision'], nullable=True),
                       _date(value['observed_at']), validity, _array(value['positions'], _position),
                       _text(value['evidence'], blank=True))


def encode_observation(value):
    if (type(value) is not Observation or type(value.observed_at) is not datetime
            or type(value.validity) is not Validity or type(value.positions) is not tuple):
        _fail()
    wire = dict(source=value.source, revision=value.revision, observed_at=value.observed_at.isoformat(),
                validity=value.validity.value, positions=[_position_wire(p) for p in value.positions], evidence=value.evidence)
    decode_observation(wire)
    return wire


_GROUPS = ('before_group', 'after_group', 'outgoing', 'incoming')
_EVENT_KEYS = ('source', 'unit', 'role', 'kind', 'before', 'after', 'previous_observed_at',
               'detected_at', 'previous_evidence', 'current_evidence', *_GROUPS, 'condition_changes', 'effective_date')


def decode_event(value):
    _object(value, _EVENT_KEYS)
    if value['effective_date'] is not None:
        _fail()
    def optional(position):
        return None if position is None else _position(position)
    def pair(items):
        if type(items) is not list or len(items) != 2:
            _fail()
        return tuple(_position(item) for item in items)
    try:
        kind = Kind(_text(value['kind']))
    except ValueError:
        _fail()
    return Event(_text(value['source']), _text(value['unit']), _text(value['role']), kind,
                 optional(value['before']), optional(value['after']), _date(value['previous_observed_at']),
                 _date(value['detected_at']), _text(value['previous_evidence'], blank=True),
                 _text(value['current_evidence'], blank=True),
                 **{key: _array(value[key], _position) for key in _GROUPS},
                 condition_changes=_array(value['condition_changes'], pair))


def encode_event(value):
    if type(value) is not Event or type(value.kind) is not Kind:
        _fail()
    wire = {key: getattr(value, key) for key in _EVENT_KEYS}
    wire['kind'] = value.kind.value
    for key in ('previous_observed_at', 'detected_at'):
        if type(wire[key]) is not datetime:
            _fail()
        wire[key] = wire[key].isoformat()
    for key in ('before', 'after'):
        wire[key] = None if wire[key] is None else _position_wire(wire[key])
    for key in (*_GROUPS, 'condition_changes'):
        if type(wire[key]) is not tuple:
            _fail()
    for key in _GROUPS:
        wire[key] = [_position_wire(p) for p in wire[key]]
    for pair in value.condition_changes:
        if type(pair) is not tuple or len(pair) != 2:
            _fail()
    wire['condition_changes'] = [[_position_wire(p) for p in pair] for pair in value.condition_changes]
    decode_event(wire)
    return wire
