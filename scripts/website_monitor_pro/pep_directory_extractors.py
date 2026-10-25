"""Pure, fail-closed pilot HTML adapters. No capture or runtime integration."""
from dataclasses import dataclass
from datetime import datetime
import re

from bs4 import BeautifulSoup, Tag

from pep_directory import Observation, Position, Validity, compare


@dataclass(frozen=True)
class Capture:
    """Caller attests URL/status, decoding and a separately reviewed inventory.

    expected_positions contains exact (unit, base-role) pairs, not database IDs.
    An inventory inferred from this same unvalidated capture is not an attestation.
    encoding applies only to bytes; text is already decoded and never re-encoded.
    """
    status: int
    source_url: str
    observed_at: datetime
    expected_positions: frozenset[tuple[str, str]]
    encoding: str


_URLS = {
    'dab': 'https://www.dab.gob.bo/personal-ejecutivo',
    'ibc': 'https://www.ibc.gob.bo/nomina.php',
    'umsa': 'https://www.umsa.bo/web/guest/rectorado',
}
_REVISION = 'pilot-html-v2-groups'


class _Reject(Exception):
    def __init__(self, validity: Validity):
        self.validity = validity


def _require(ok: bool, validity: Validity = Validity.INCOMPLETE) -> None:
    if not ok:
        raise _Reject(validity)


def _one(root: BeautifulSoup | Tag, selector: str) -> Tag:
    matches = root.select(selector)
    _require(bool(matches))
    _require(len(matches) == 1, Validity.AMBIGUOUS)
    return matches[0]


def _text(tag: Tag) -> str:
    # Only layout whitespace; never repair names or strip accents/titles.
    return ' '.join(tag.get_text(' ', strip=True).split())


def _role(value: str) -> tuple[str, str | None]:
    # Observed DAB suffix is a condition, not part of role identity.
    match = re.search(r'\s+(a\.i\.)$', value, re.IGNORECASE)
    if match:
        return value[:match.start()].strip(), match.group(1)
    return value, None


def _position(unit: str, role: str, person: str, row: Tag) -> Position:
    base_role, condition = _role(role)
    # Blank names are partial extraction, NOT an attested vacancy. No guessed exits.
    _require(bool(unit and base_role and person))
    # Reject only unambiguous contact-only values; no alphabet/name-shape test.
    contact = (re.fullmatch(r'(?:mailto:)?[^\s@]+@[^\s@]+\.[^\s@]+', person, re.IGNORECASE)
               or re.fullmatch(r'(?:(?:https?|ftp)://|www\.)\S+', person, re.IGNORECASE)
               or re.fullmatch(r'(?=.*\d)(?:tel:)?\+?[\d\s().-]+', person, re.IGNORECASE))
    _require(not contact)
    return Position(unit, base_role, person, condition, str(row))


def _rows(table: Tag, headers: list[str], *, allow_empty: bool = False) -> list[Tag]:
    head = _one(table, 'thead')
    _require([_text(th) for th in head.select('th')] == headers)
    _require(len(head.select('tr')) == 1)
    body = _one(table, 'tbody')
    _require(all((child.name == 'tr' if isinstance(child, Tag) else not str(child).strip())
                 for child in body.children))
    rows = body.find_all('tr', recursive=False)
    _require(bool(rows) or allow_empty)
    _require(len(table.select('tr')) == len(rows) + 1)
    # Every data cell must belong to an extracted row, not an ignored table fragment.
    _require(len(table.select('td')) == len(rows) * len(headers))
    for row in rows:
        _require(len(row.find_all('td', recursive=False)) == len(headers))
        _require(len(row.select('td')) == len(headers))
    return rows


def _dab(soup: BeautifulSoup) -> list[Position]:
    directory = _one(soup, '.view-personal-ejecutivo')
    table = _one(directory, 'table.views-table')
    positions = []
    for row in _rows(table, ['Nro', 'Nombre Completo', 'Cargo', 'Unidad']):
        person = _one(row, 'td.views-field-field-nombre-personal-ejecutivo')
        role = _one(row, 'td.views-field-field-cargo-personal-ejecutivo')
        unit = _one(row, 'td.views-field-field-unidad-personal-ejecutivo')
        _require(all(t.parent is row for t in (person, role, unit)))
        positions.append(_position(_text(unit), _text(role), _text(person), row))
    return positions


def _ibc(soup: BeautifulSoup) -> list[Position]:
    directory = _one(soup, '#contenido')
    cards = directory.select('.card')
    _require(bool(cards))
    _require(len(directory.select('table')) == len(cards))
    positions = []
    units = set()
    for card in cards:
        unit = _text(_one(card, '.card-header'))
        _require(bool(unit))
        _require(unit not in units, Validity.AMBIGUOUS)
        units.add(unit)
        table = _one(card, '.card-body table.table')
        for row in _rows(table, ['Ítem', 'Nombres y Apellidos', 'Cargo/Unidad'], allow_empty=True):
            cells = row.find_all('td', recursive=False)
            # Real layout has exactly two text runs separated by one <br>.
            # Flattening this cell would conflate the role and its unit.
            context = cells[2]
            _require(len(context.select('br')) == 1)
            parts = [part.strip() for part in context.stripped_strings]
            _require(len(parts) == 2 and all(parts))
            role, contextual_unit = parts
            _require(contextual_unit == unit, Validity.AMBIGUOUS)
            positions.append(_position(unit, role, _text(cells[1]), row))
    return positions


def _umsa(soup: BeautifulSoup) -> list[Position]:
    # This URL is the single Rectorado profile, not the university-wide directory.
    content = _one(soup, '#content')
    row = _one(content, '.journal-content-article .TablaSpeed .filaPersona')
    details = _one(row, '.col-2b .parte2')
    fields = details.find_all('b', recursive=False)
    _require(len(fields) == 2)
    _require(len(details.select('b')) == 2)
    person, role = map(_text, fields)
    _require(role in ('Rectora UMSA', 'Rector UMSA'))
    # Only these two evidenced labels denote this same office; source label stays
    # untouched in details evidence. Inventories must use this canonical role.
    return [_position('Rectorado', 'Rectorado UMSA', person, details)]


def extract(profile: str, body: bytes | str, capture: Capture) -> Observation:
    """Return T1 evidence/validity; callers MUST qualify via T1 compare().

    Unknown profiles are programmer errors. Rejected captures have no positions.
    Byte decode failures have empty textual evidence: callers must retain original
    bytes outside this text-only T1 contract if forensic replay is needed.
    """
    if profile not in _URLS:
        raise ValueError('unknown directory profile')
    evidence = ''

    def observation(validity: Validity, positions: tuple[Position, ...] = ()) -> Observation:
        return Observation(profile + ':directory', _REVISION, capture.observed_at,
                           validity, positions, evidence)

    try:
        try:
            evidence = body.decode(capture.encoding, errors='strict') if isinstance(body, bytes) else body
        except (LookupError, UnicodeDecodeError):
            raise _Reject(Validity.FAILED) from None
        _require(isinstance(evidence, str), Validity.FAILED)
        _require(capture.status == 200 and capture.source_url == _URLS[profile], Validity.FAILED)
        _require(bool(evidence.strip()), Validity.EMPTY)
        soup = BeautifulSoup(evidence, 'html.parser')
        title = soup.title.get_text(' ', strip=True).lower() if soup.title else ''
        visible = soup.get_text(' ', strip=True).lower()
        _require(not (any(marker in title for marker in ('just a moment', 'access denied', 'captcha'))
                      or visible.strip() in ('access denied', 'forbidden')
                      or soup.select('[id*="captcha"], .cf-challenge, #challenge-form')),
                 Validity.BLOCKED)
        # html.parser repairs truncated documents: require explicit complete envelope
        # BEFORE relying on relationships; never treat its repairs as completeness.
        _require(len(soup.select('html')) == 1 and len(soup.select('body')) == 1)
        _require(bool(re.search(r'</body\s*>\s*</html\s*>\s*$', evidence, re.IGNORECASE)))
        _require(not soup.select('[rel~="next"], .pagination, .pager'))
        _require(isinstance(capture.expected_positions, frozenset) and bool(capture.expected_positions))
        positions = {'dab': _dab, 'ibc': _ibc, 'umsa': _umsa}[profile](soup)
        keys = {(p.unit, p.role) for p in positions}
        _require(keys == capture.expected_positions)
        candidate = observation(Validity.VALID, tuple(positions))
        # Reuse T1 identity (including reversible mojibake equivalence) instead of
        # creating a second matching policy. Callers still qualify all T1 checks.
        reasons = compare(None, candidate).reasons
        _require(not any(reason in ('duplicate_position', 'ambiguous_membership')
                         for reason in reasons), Validity.AMBIGUOUS)
        return candidate
    except _Reject as rejected:
        return observation(rejected.validity)
