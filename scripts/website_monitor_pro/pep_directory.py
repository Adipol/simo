"""Pure, source-local directory comparison. No I/O or stored state."""
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from enum import Enum


class Validity(str, Enum):
    VALID = "valid"
    FAILED = "failed"
    BLOCKED = "blocked"
    EMPTY = "empty"
    INCOMPLETE = "incomplete"
    AMBIGUOUS = "ambiguous"


class Kind(str, Enum):
    POSSIBLE_REPLACEMENT = "possible_replacement"
    NO_CHANGE = "no_change"
    POSSIBLE_DEPARTURE = "possible_departure_successor_unknown"
    FIRST_OBSERVATION = "first_observation"
    CONDITION_CHANGE = "condition_change"
    REVIEW_REQUIRED = "review_required"


class Disposition(str, Enum):
    BASELINE = "baseline"
    REBASELINED = "rebaselined"
    COMPARED = "compared"
    REJECTED = "rejected"


@dataclass(frozen=True)
class Position:
    unit: str
    role: str
    person: str | None
    condition: str | None
    evidence: str


@dataclass(frozen=True)
class Observation:
    # source identifies the institution AND directory, not just a page URL.
    source: str
    revision: str | None
    observed_at: datetime
    validity: Validity
    positions: tuple[Position, ...]
    evidence: str


@dataclass(frozen=True)
class Event:
    source: str
    unit: str
    role: str
    kind: Kind
    before: Position | None
    after: Position | None
    previous_observed_at: datetime
    detected_at: datetime
    previous_evidence: str
    current_evidence: str

    # Detection alone never establishes a departure/appointment date.
    effective_date: None = field(default=None, init=False)


@dataclass(frozen=True)
class Comparison:
    disposition: Disposition
    baseline: Observation | None
    events: tuple[Event, ...] = ()
    reasons: tuple[str, ...] = ()


def _text(value: object) -> bool:
    return isinstance(value, str) and bool(value.strip())


def _person_key(name: str | None) -> str | None:
    """Repair one reversible UTF-8-as-Western decoding layer, or fail closed.

    No fuzzy matching, transliteration, case folding or arbitrary conversions.
    Marker-bearing names without a unique marker-free repair need review.
    """
    if name is None:
        return None
    markers = ("Ã", "Â", "â")
    if "\ufffd" in name:
        raise ValueError("replacement character")
    if not any(marker in name for marker in markers):
        return name
    candidates = set()
    for codec in ("latin1", "cp1252"):
        try:
            repaired = name.encode(codec).decode("utf8")
            reversible = repaired.encode("utf8").decode(codec) == name
        except (UnicodeEncodeError, UnicodeDecodeError):
            continue
        if (reversible and repaired != name and "\ufffd" not in repaired
                and not any(marker in repaired for marker in markers)):
            candidates.add(repaired)
    if len(candidates) != 1:
        raise ValueError("ambiguous or unrepairable encoding")
    return candidates.pop()


def _validate(observation: Observation) -> tuple[str, ...]:
    if not isinstance(observation, Observation):
        return ("invalid_structure",)
    if not isinstance(observation.validity, Validity):
        return ("invalid_structure",)
    if observation.validity != Validity.VALID:
        return ("capture_" + observation.validity.value,)
    if (not _text(observation.source) or not _text(observation.evidence)
            or (observation.revision is not None and not _text(observation.revision))
            or not isinstance(observation.observed_at, datetime)
            or observation.observed_at.tzinfo is None
            or observation.observed_at.utcoffset() is None
            or not isinstance(observation.positions, tuple)):
        return ("invalid_structure",)
    if not observation.positions:
        return ("empty_positions",)
    keys = set()
    for position in observation.positions:
        if (not isinstance(position, Position) or not _text(position.unit)
                or not _text(position.role) or not _text(position.evidence)
                or (position.person is not None and not _text(position.person))
                or (position.condition is not None and not _text(position.condition))):
            return ("invalid_structure",)
        key = (position.unit, position.role)
        if key in keys:
            return ("duplicate_position",)
        keys.add(key)
    for position in observation.positions:
        try:
            _person_key(position.person)
        except ValueError:
            return ("uncertain_person_encoding",)
    return ()


def _instant(value: datetime) -> timedelta:
    """Exact UTC ordering key for a validated aware datetime, including fold.

    A duration can extend beyond datetime.min/max when subtracting the offset;
    converting to a UTC datetime could overflow. Avoid float timestamp rounding.
    """
    return (timedelta(days=value.toordinal(), hours=value.hour, minutes=value.minute,
                      seconds=value.second, microseconds=value.microsecond)
            - value.utcoffset())


def compare(baseline: Observation | None, current: Observation) -> Comparison:
    """Return a qualified next baseline and ordered classifications, never write.

    A caller-provided baseline must already be valid; malformed baselines raise
    ValueError. Rejected current input leaves that baseline untouched. Callers
    own persistence/history and must partition by stable source identity.
    """
    if baseline is not None and _validate(baseline):
        raise ValueError("baseline must be a valid qualified observation")
    reasons = _validate(current)
    if reasons:
        return Comparison(Disposition.REJECTED, baseline, reasons=reasons)
    if baseline is None:
        return Comparison(Disposition.BASELINE, current)
    if baseline.source != current.source:
        return Comparison(Disposition.REJECTED, baseline, reasons=("source_mismatch",))
    if _instant(current.observed_at) < _instant(baseline.observed_at):
        return Comparison(Disposition.REJECTED, baseline, reasons=("older_observation",))
    if (baseline.revision is None or current.revision is None
            or baseline.revision != current.revision):
        return Comparison(Disposition.REBASELINED, current, reasons=("revision_boundary",))

    before = {(p.unit, p.role): p for p in baseline.positions}
    after = {(p.unit, p.role): p for p in current.positions}
    events = []
    for unit, role in sorted(before.keys() | after.keys()):
        old, new = before.get((unit, role)), after.get((unit, role))
        old_name = _person_key(old.person) if old else None
        new_name = _person_key(new.person) if new else None
        if new is None:
            kind = Kind.REVIEW_REQUIRED
        elif old is None:
            kind = Kind.FIRST_OBSERVATION
        elif old_name == new_name:
            kind = Kind.CONDITION_CHANGE if old.condition != new.condition else Kind.NO_CHANGE
        elif old_name is None:
            kind = Kind.FIRST_OBSERVATION
        elif new_name is None:
            kind = Kind.POSSIBLE_DEPARTURE
        else:
            kind = Kind.POSSIBLE_REPLACEMENT
        events.append(Event(current.source, unit, role, kind, old, new,
                            baseline.observed_at, current.observed_at,
                            baseline.evidence, current.evidence))
    return Comparison(Disposition.COMPARED, current, tuple(events))
