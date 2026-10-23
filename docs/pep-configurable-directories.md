# Configurable directory extraction

`pep_directory_configurable.py` parses explicitly mapped tables and cards without
an institution allowlist. It returns the existing `Observation` / `Position`
structures. **It does not infer a configuration, approve a source, persist anything,
fetch a URL, or enable monitoring.** Existing `extract(profile, body, capture)`,
comparison, codec, storage, shadow observer and UI remain unchanged.

## Public API and quick path

```python
from pep_directory_configurable import DirectoryConfig, extract_configured
from pep_directory_extractors import Capture
from pep_directory import compare

config = DirectoryConfig.from_dict({
    "source": "fictional-council:directory",
    "url": "https://council.example/directory",
    "directory": "#directory",
    "record": "tbody > tr",
    "columns": 3,
    "fields": {
        "unit": {"constant": "North"},
        "role": {"column": 0},
        "person": {"column": 1},
        "condition": {"column": 2},
    },
})
# capture is the existing Capture: status, exact final source_url, aware time,
# independently reviewed expected_positions, explicit encoding for bytes.
candidate = extract_configured(config, saved_body, capture)
qualification = compare(previous_qualified_baseline, candidate)
```

`DirectoryConfig.from_dict(data)` rejects malformed configuration with `ValueError`.
Use this factory rather than constructing the frozen dataclass directly. No input
dictionaries are retained: nested mappings become immutable fields in a sorted tuple.
`config.canonical_identity()` returns canonical JSON; `config.revision` is
`configured-html-v1:<SHA-256>` over that identity, including the parser contract.
Dictionary order alone has no effect. Mapping, source or URL changes change the
revision; body, people, timestamps and inventory do not enter this fingerprint.
A changed revision rebaselines through existing `compare()`, **not approval**.
Future inventory/approval lifecycle revisions must be independently bound at the
submission boundary, as with existing shadow qualification; this module does not
replace that boundary. Incompatible parser semantics require a contract-version bump.

## Mapping associations

Required top-level keys are `source`, `url`, `directory`, `record`, `fields`.
Only `section` and `columns` are optional. Fields require `unit`, `role`, `person`;
`condition` is optional. Unknown keys and null optional keys are rejected.

| Field mapping | Meaning |
| --- | --- |
| `{"selector": ".details > p"}` | Exactly one descendant within this record |
| `{"column": 1}` | Zero-based direct td/th cell of a tr; requires explicit `columns` count |
| `{"scope": "section", "selector": "h2"}` | Unit context inside the current section; requires `section` |
| `{"scope": "directory", "selector": "h1"}` | Unit context shared by the directory, outside sections/records |
| `{"constant": "North"}` | Explicit unit only; not a person roster or hardcoded role list |

Selector scope defaults to `record`; explicit `record` is equivalent. Only unit
may use section/directory scope or a constant. Exactly one mapping mode per field
is allowed. Identical mappings are rejected; selected identical or nested field
nodes also reject the capture. A declared condition must be nonblank; an omitted
condition becomes `None`. Names and roles receive only layout whitespace cleanup:
no suffix stripping, gender/title aliases, fuzzy matching, or invented conditions.
The existing three pilot profiles retain their own unchanged a.i./Rectorado rules.

For sectioned tables, add `"section": "section"` and replace the constant unit
with `{"scope": "section", "selector": "h2"}`. Every section must contain records;
sections and records may not overlap or nest, and every directory-selected record
must belong to exactly one section. Unit text cannot be borrowed from another record.
Position evidence includes the unit context element plus the record DOM.

For nested cards, omit `columns` and use e.g. `"record": "article"`,
`"role": {"selector": ".details > h2"}` and
`"person": {"selector": ".details > p"}`. Each selected article binds its own
fields. Several records may share unit/role with distinct holders. They remain a
Position sequence, not independent arrays zipped into imaginary replacements.

Selectors support only ASCII tag names, `.class`, `#id`, descendant whitespace
and `>` child combinators. No attributes, wildcards, pseudo-selectors, sibling
selectors, lists, escapes, executable regex, templates, imports or arbitrary code
are accepted. This deliberately small subset is not a selector-engine framework.
A dictionary cannot expose duplicate keys already overwritten by a JSON decoder;
a future configuration-file loader must reject duplicate JSON keys before this API.

## Qualification and pending behavior

The [existing Capture/core contract](pep-directory-transitions.md) still applies:
HTTP 200, exact configured final URL, aware timestamp and strict explicitly declared
byte decoding. Already-decoded text remains untouched; no charset guessing or
replacement-error decoding. Decode failure has empty text evidence. URL validation
checks HTTP(S) structure, no credentials/fragment/whitespace and a usable host/port;
it is **not SSRF protection or fetching authorization**, including for private IPs.
No network operation exists here.

A complete HTML/body envelope with explicit final closing tags is required.
Blocked pages, known pagination markers, malformed nesting, duplicate attributes,
missing/ambiguous boundaries, absent/blank fields, spanning cells (even span=1),
wrong direct cell counts, nested cell tables and obvious contact-only person cells
fail closed. Structural errors discard **all** positions, never a qualified subset.
No blank field means vacancy; explicit vacancies are not supported in this unit.

A nonempty, separately reviewed `Capture.expected_positions` frozenset of exact
(unit, role) tuples must match the extracted identities. Missing/extra identities
reject the whole capture. Missing, empty or malformed inventory leaves an otherwise
successful parse `INCOMPLETE` with inspectable positions, not a qualified baseline.
Never build this attestation from the candidate itself to claim completeness.
An inventory attests role identities, not holder counts: a partial server response
that retains every role but omits a co-holder cannot be proven complete by this
parser alone. Selectors also cannot prove that an unselected area contains no roles.

The module calls existing `compare(None, candidate)` for shared qualification,
including duplicate holders and conservative encoding checks; it implements no
second person-identity policy. Callers must still use `compare(previous, candidate)`
for source/revision/chronology qualification. Invalid candidates retain the previous
baseline and emit no events. Unchanged groups produce existing `no_change`
classifications, not alerts. Raw decoded page text is `Observation.evidence`;
Position DOM serialization may normalize HTML quoting/entities, unlike the full page.

## Declared resource limits

Limits are fixed contract policy, not user-controlled configuration:

- Body: 1,048,576 bytes or decoded characters; oversize input is rejected before
  decoding/parsing, with empty evidence. No raw-byte archive is added.
- Preflight: at most 20,000 start tags and depth 64 before DOM construction.
  Explicit balanced nesting is required; optional HTML end tags are not inferred.
- Selectors: 128 characters, eight compounds, at most four class/id suffixes each.
- Source/unit/field text: 512 characters; URL: 2,048; columns: 1–32.
- Exactly one directory; at most 100 sections and 1,000 records total; fields
  select exactly one node. More than one matching required node is ambiguous.
- Position serialized evidence: 8,192 characters each, 4,194,304 total.

These conservative limits bound memory and selector/cardinality work for offline
parsing; they are not a hostile-document sandbox or a universal HTML validator.
Resource budgets are not wall-clock guarantees.

## Verification and next gate

Synthetic tests cover flat tables, sectioned tables and nested cards, full public
observations/evidence, grouped transitions, condition changes, order independence,
strict configuration, metadata/decoding failures, overlapping/missing associations,
resource limits, stable revisions and unreviewed inventories. No real people or
contacts are copied.

This is an exact historical module/test port, so there is no meaningful new
pre-implementation behavioral RED. Current-candidate verification is recorded
separately from historical development evidence.

Fresh offline validation with Python 3.14.8, in two separate processes with
bytecode writes disabled, passed the focused 11 tests and the combined 63 tests
(26 core, 21 pilot extractor, 5 codec, 11 configurable), both exit 0 with no
failures, errors or skips. The combined invocation loaded core tests first.
Commands, run from `scripts/website_monitor_pro` with the existing test environment
and no external `PYTHONPATH`, were:

```sh
python -B -m unittest -v tests.test_pep_directory_configurable
python -B -m unittest -v tests.test_pep_directory tests.test_pep_directory_extractors tests.test_pep_directory_codec tests.test_pep_directory_configurable
```

The module and tests are byte-identical to their historical source. Git diff and
blob hashes confirm the core, pilot extractors, codec and their tests are unchanged
from the preceding delivery, including DST, unknown-holder and orphan-cell fixes.
No shared-code changes were made, so monitor differential testing is not applicable;
this proof is limited to unchanged dependencies and the named offline regressions.
No database, container, network, browser, install, download or monitor import was
used. The persistent synthetic target was neither accessed nor modified.

Next gate is immutable configuration/preview correction and explicit confirmation
authority, serialized with source submission eligibility. Persistence, preview UI,
source binding, activation, live capture qualification and operational readiness
remain unimplemented here. A successful parse or rebaseline is never permission
to persist or activate.
