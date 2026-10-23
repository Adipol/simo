"""Pure bounded declarative table/card extraction; no approval or I/O authority."""
from dataclasses import asdict, dataclass
from datetime import datetime
from hashlib import sha256
from html.parser import HTMLParser
import json
import re
from urllib.parse import urlsplit

from bs4 import BeautifulSoup, Tag

from pep_directory import Observation, Position, Validity, compare
from pep_directory_extractors import Capture

_CONTRACT = 'configured-html-v1'
_MAX_BODY = 1_048_576
_COMPOUND = r'(?:[a-zA-Z][a-zA-Z0-9_-]*)?(?:[.#][a-zA-Z_][a-zA-Z0-9_-]*){0,4}'
_SELECTOR = re.compile(_COMPOUND)


def _check(ok: bool) -> None:
    if not ok:
        raise ValueError('invalid directory configuration')


def _string(value: object, limit: int = 512) -> bool:
    return isinstance(value, str) and 0 < len(value) <= limit and value == value.strip() and bool(value)


def _selector(value: object) -> str:
    _check(_string(value, 128))
    # No pseudo-selectors, attributes, escapes, wildcards, siblings or lists.
    tokens = re.findall(r'>|[^\s>]+', value)
    _check(tokens[0] != '>' and tokens[-1] != '>')
    _check(sum(token != '>' for token in tokens) <= 8)
    _check(all(token != '>' or tokens[index - 1] != '>' for index, token in enumerate(tokens)))
    _check(all(token == '>' or bool(_SELECTOR.fullmatch(token)) for token in tokens))
    return value


@dataclass(frozen=True)
class _Field:
    scope: str = 'record'
    selector: str | None = None
    column: int | None = None
    constant: str | None = None


@dataclass(frozen=True, init=False)
class DirectoryConfig:
    """Use from_dict; only immutable scalar/tuple state is retained."""
    source: str
    url: str
    directory: str
    record: str
    fields: tuple[tuple[str, _Field], ...]
    section: str | None = None
    columns: int | None = None

    def __init__(self) -> None:
        raise ValueError('use DirectoryConfig.from_dict')

    @classmethod
    def from_dict(cls, data: object) -> 'DirectoryConfig':
        _check(type(data) is dict and 5 <= len(data) <= 7
               and {'source', 'url', 'directory', 'record', 'fields'} <= data.keys())
        _check(data.keys() <= {'source', 'url', 'directory', 'record', 'fields', 'section', 'columns'})
        _check(_string(data['source']) and _string(data['url'], 2048))
        try:
            url = urlsplit(data['url'])
            _check(url.scheme in ('https', 'http') and bool(url.hostname) and url.port != 0
                   and url.username is None and url.password is None
                   and '#' not in data['url'] and '\\' not in data['url']
                   and not any(c.isspace() or ord(c) < 32 or ord(c) == 127 for c in data['url']))
        except (ValueError, TypeError):
            raise ValueError('invalid source URL') from None
        directory, record = _selector(data['directory']), _selector(data['record'])
        section = _selector(data['section']) if 'section' in data else None
        columns = data.get('columns')
        _check('columns' not in data or type(columns) is int and 1 <= columns <= 32)
        fields = data['fields']
        _check(type(fields) is dict and 3 <= len(fields) <= 4 and {'unit', 'role', 'person'} <= fields.keys()
               and fields.keys() <= {'unit', 'role', 'person', 'condition'})
        parsed = []
        used = set()
        for name, value in sorted(fields.items()):
            _check(type(value) is dict and 1 <= len(value) <= 2)
            if set(value) == {'constant'}:
                _check(name == 'unit' and _string(value['constant']))
                field = _Field(constant=value['constant'])
            elif set(value) == {'column'}:
                column = value['column']
                _check(type(column) is int and columns is not None and 0 <= column < columns)
                field = _Field(column=column)
            else:
                _check(set(value) in ({'selector'}, {'selector', 'scope'}))
                scope = value.get('scope', 'record')
                _check(isinstance(scope, str) and scope in ('record', 'section', 'directory'))
                _check(name == 'unit' or scope == 'record')
                _check(scope != 'section' or section is not None)
                field = _Field(scope=scope, selector=_selector(value['selector']))
            _check(field not in used)
            used.add(field)
            parsed.append((name, field))
        config = object.__new__(cls)
        for name, value in (('source', data['source']), ('url', data['url']),
                            ('directory', directory), ('record', record), ('fields', tuple(parsed)),
                            ('section', section), ('columns', columns)):
            object.__setattr__(config, name, value)
        return config

    def canonical_identity(self) -> str:
        """Canonical JSON identity, including the implementation contract version."""
        return json.dumps({'contract': _CONTRACT, 'config': asdict(self)},
                          sort_keys=True, ensure_ascii=True, separators=(',', ':'))

    @property
    def revision(self) -> str:
        return _CONTRACT + ':' + sha256(self.canonical_identity().encode('utf-8')).hexdigest()


class _Reject(Exception):
    def __init__(self, validity: Validity = Validity.INCOMPLETE):
        self.validity = validity


def _require(ok: bool, validity: Validity = Validity.INCOMPLETE) -> None:
    if not ok:
        raise _Reject(validity)


class _Budget(HTMLParser):
    """Bound depth/tag count before BeautifulSoup builds its DOM."""
    def __init__(self) -> None:
        super().__init__(convert_charrefs=True)
        self.stack: list[str] = []
        self.nodes = 0

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.nodes += 1
        _require(self.nodes <= 20_000 and len({name for name, _ in attrs}) == len(attrs))
        if tag not in {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
                       'link', 'meta', 'param', 'source', 'track', 'wbr'}:
            self.stack.append(tag)
            _require(len(self.stack) <= 64)

    def handle_startendtag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        self.handle_starttag(tag, attrs)
        self.handle_endtag(tag)

    def handle_endtag(self, tag: str) -> None:
        if tag in self.stack:
            # HTML's optional closing-tag recovery is deliberately not inferred.
            _require(self.stack[-1] == tag)
            self.stack.pop()


def _select(root: BeautifulSoup | Tag, selector: str, limit: int) -> list[Tag]:
    tags = root.select(selector, limit=limit + 1)
    _require(len(tags) <= limit, Validity.AMBIGUOUS if limit == 1 else Validity.INCOMPLETE)
    return tags


def _one(root: BeautifulSoup | Tag, selector: str) -> Tag:
    tags = _select(root, selector, 1)
    _require(bool(tags))
    return tags[0]


def _disjoint(tags: list[Tag]) -> None:
    identities = {id(tag) for tag in tags}
    _require(all(not any(id(parent) in identities for parent in tag.parents) for tag in tags),
             Validity.AMBIGUOUS)


def _overlap(left: Tag, right: Tag) -> bool:
    return left is right or any(p is right for p in left.parents) or any(p is left for p in right.parents)


def _positions(config: DirectoryConfig, directory: Tag) -> tuple[Position, ...]:
    sections = _select(directory, config.section, 100) if config.section else [directory]
    _require(bool(sections))
    _disjoint(sections)
    all_records = _select(directory, config.record, 1000)
    _require(bool(all_records))
    _disjoint(all_records)
    record_ids = {id(r) for r in all_records}
    assigned: set[int] = set()
    positions = []
    evidence_size = 0
    for section in sections:
        records = _select(section, config.record, 1000)
        _require(bool(records))  # no inferred empty section/vacancy
        for record in records:
            _require(id(record) in record_ids and id(record) not in assigned, Validity.AMBIGUOUS)
            assigned.add(id(record))
            _require(not record.select('[rowspan], [colspan]'))
            cells = record.find_all(['td', 'th'], recursive=False)
            if config.columns is not None:
                _require(record.name == 'tr' and len(cells) == config.columns)
                _require(len(record.select('td, th')) == len(cells))
            nodes: list[Tag] = []
            context: list[Tag] = []
            values = {}
            for name, field in config.fields:
                if field.constant is not None:
                    values[name] = field.constant
                    continue
                root = {'record': record, 'section': section, 'directory': directory}[field.scope]
                node = cells[field.column] if field.column is not None else _one(root, field.selector)
                # Context unit may not be borrowed from an occupant/another section.
                if field.scope != 'record':
                    _require(not any(_overlap(node, r) for r in all_records), Validity.AMBIGUOUS)
                    if field.scope == 'directory' and config.section:
                        _require(not any(_overlap(node, s) for s in sections), Validity.AMBIGUOUS)
                    context.append(node)
                _require(not any(_overlap(node, other) for other in nodes), Validity.AMBIGUOUS)
                nodes.append(node)
                value = ' '.join(node.get_text(' ', strip=True).split())
                _require(_string(value))
                values[name] = value
            person = values['person']
            # Obvious contact-only cells are not holder evidence; no fuzzy/name roster.
            _require(not re.fullmatch(r'(?:mailto:)?[^\s@]+@[^\s@]+\.[^\s@]+|'
                                     r'(?:(?:https?|ftp)://|www\.)\S+|'
                                     r'(?=.*\d)(?:tel:)?\+?[\d\s().-]+', person, re.I))
            evidence = ''.join(str(node) for node in context) + str(record)
            evidence_size += len(evidence)
            _require(len(evidence) <= 8192 and evidence_size <= 4_194_304)
            positions.append(Position(values['unit'], values['role'], person,
                                      values.get('condition'), evidence))
    _require(assigned == record_ids, Validity.AMBIGUOUS)
    return tuple(positions)


def extract_configured(config: DirectoryConfig, body: bytes | str, capture: Capture) -> Observation:
    """Parse only; inventory attestation and compare() are not approval authority.

    Structural rejection returns no positions. A complete parse without a reviewed
    inventory remains INCOMPLETE but retains positions for a future preview.
    """
    if not isinstance(config, DirectoryConfig) or not isinstance(capture, Capture):
        raise ValueError('DirectoryConfig and Capture required')
    evidence = ''

    def result(validity: Validity, positions: tuple[Position, ...] = ()) -> Observation:
        return Observation(config.source, config.revision, capture.observed_at, validity, positions, evidence)

    try:
        _require(isinstance(body, (bytes, str)), Validity.FAILED)
        _require(len(body) <= _MAX_BODY)
        try:
            evidence = body.decode(capture.encoding, errors='strict') if isinstance(body, bytes) else body
        except (LookupError, UnicodeError, TypeError):
            raise _Reject(Validity.FAILED) from None
        _require(type(capture.status) is int and capture.status == 200
                 and capture.source_url == config.url
                 and isinstance(capture.observed_at, datetime)
                 and capture.observed_at.tzinfo is not None
                 and capture.observed_at.utcoffset() is not None, Validity.FAILED)
        _require(bool(evidence.strip()), Validity.EMPTY)
        budget = _Budget()
        budget.feed(evidence)
        budget.close()
        _require(not budget.stack)
        soup = BeautifulSoup(evidence, 'html.parser')
        title = soup.title.get_text(' ', strip=True).lower() if soup.title else ''
        visible = soup.get_text(' ', strip=True).lower()
        _require(not (any(m in title for m in ('just a moment', 'access denied', 'captcha'))
                      or visible in ('access denied', 'forbidden')
                      or soup.select('[id*="captcha"], .cf-challenge, #challenge-form')), Validity.BLOCKED)
        _one(soup, 'html')
        _one(soup, 'body')
        _require(bool(re.search(r'</body\s*>\s*</html\s*>\s*$', evidence, re.I)))
        _require(not soup.select('[rel~="next"], .pagination, .pager'))
        directory = _one(soup, config.directory)
        positions = _positions(config, directory)
        candidate = result(Validity.VALID, positions)
        reasons = compare(None, candidate).reasons
        _require(not reasons, Validity.AMBIGUOUS if any(r in reasons for r in
                 ('duplicate_position', 'ambiguous_membership')) else Validity.INCOMPLETE)
        expected = capture.expected_positions
        reviewed = (type(expected) is frozenset and bool(expected)
                    and len(expected) <= 1000 and all(type(pair) is tuple and len(pair) == 2
                    and all(_string(v) for v in pair) for pair in expected))
        if not reviewed:
            return result(Validity.INCOMPLETE, positions)
        _require({(p.unit, p.role) for p in positions} == expected)
        return candidate
    except _Reject as rejected:
        return result(rejected.validity)
