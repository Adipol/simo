# Pure directory comparison contract (T1)

`scripts/website_monitor_pro/pep_directory.py` is a Python-stdlib-only library.
It performs no I/O, imports no monitor, configures no logging, and stores no
observations, history, counters, proposals or baselines. It never writes `cambios`
or enters the legacy Gemini pending queue. Capture adapters, persistence,
monitor integration and review UI belong to later tasks.

## Public input

All structures are frozen dataclasses. Pass `Observation` or `None` as the
baseline and an `Observation` as current input to `compare(baseline, current)`.

- `Observation(source, revision, observed_at, validity, positions, evidence)`:
  `source` is a stable identity for **institution plus directory**; adapters must
  not use a shared label across institutions. `revision` is the extraction
  contract revision, a nonblank string or `None` for unknown. `observed_at` is a
  timezone-aware Python `datetime`. `positions` must be a nonempty tuple.
  `evidence` is the original, nonblank captured evidence string.
- `Position(unit, role, person, condition, evidence)`: nonblank unit, role and raw
  position evidence; person/condition are nonblank strings or `None` for unknown.
  Preserve the original name and condition, including `a.i.`. Supply a stable
  explicit unit identity, not an empty string, even for a single-unit directory.
- `Validity`: `valid`, `failed`, `blocked`, `empty`, `incomplete`, `ambiguous`.
  Adapters declare capture validity; this library does **not** infer HTML
  completeness, pagination, blocking, layout quality or institutional hierarchy.
  In particular, an absent role on a partial page must not be declared a valid
  complete capture. Empty position sets are rejected even when declared valid.

Role identity is exactly `(source, unit, role)`. Multiple distinct people may
occupy that role; membership identity uses the conservative person comparison
key below. No case folding, fuzzy matching, role alias inference or cross-unit
pairing is performed. Repeated equivalent people within a role reject the whole
observation, including reversible-mojibake duplicates or conflicting conditions.
A single `person=None` is an explicit unknown holder, not a named member. Mixing
unknown and named holders within one role rejects as ambiguous membership.
A present unknown role differs from a missing role.

## Comparison result

`Comparison(disposition, baseline, events, reasons)` contains tuples for events
and reason codes. The qualified next baseline is the exact accepted current
observation, even when unchanged, so latest raw evidence/time are not lost.
Retain previous observations separately when implementing persistence; the pure
result is not a history store and must not be treated as indefinite tenure.

| Disposition | Baseline / events |
| --- | --- |
| `baseline` | First valid input; current baseline, no events |
| `rebaselined` | Unknown or changed revision; current baseline, no events; reason `revision_boundary` |
| `compared` | Same source/known revision; current baseline, classifications sorted by `(unit, role)` |
| `rejected` | Previous baseline unchanged (possibly `None`); no events |

Rejected observations return one reason: `capture_<validity>`,
`invalid_structure`, `empty_positions`, `duplicate_position`,
`ambiguous_membership`, `uncertain_person_encoding`, `source_mismatch`, or
`older_observation`.
Structural/capture checks precede source, chronological and revision checks.
A malformed caller-supplied baseline raises `ValueError`: only accepted results
should be supplied as baselines. Equal absolute UTC instants are allowed;
strictly older instants cannot roll back a baseline, including across DST folds
and different UTC offsets. Original timestamp objects are retained in observations
and events. This defines no stored idempotency mechanism. Constructors themselves
do not validate; `compare` is the qualification boundary for these typed immutable
inputs.

Each `Event` contains `source`, `unit`, `role`, `kind`, original `before`/`after`
positions (either may be `None`), `previous_observed_at`, `detected_at`, original
`previous_evidence`/`current_evidence`, and `effective_date=None`. Legacy `before`
and `after` remain the exact original Position for a singleton on that respective
side; they are `None` for absent or multi-holder groups. **Never infer a missing
role from those convenience fields; inspect the group fields.**
Detection time is **not** an appointment or departure date. Classifications are
proposals/observations, never confirmed legal changes:

| Before → after (synthetic examples) | Kind |
| --- | --- |
| Ana Ejemplo → Beatriz Ejemplo in the same role | `possible_replacement` |
| Carlos Ejemplo → Carlos Ejemplo | `no_change` |
| Ana Ejemplo → explicit unnamed role | `possible_departure_successor_unknown` |
| New role, or unnamed role → Laura Ejemplo | `first_observation` |
| Ana Ejemplo, titular → Ana Ejemplo, a.i. | `condition_change` |
| Entire role absent/renamed | `review_required` for the old identity |

### Role-holder groups

Every event additionally exposes immutable tuples (all default to empty for
backward-compatible construction):

- `before_group`, `after_group`: every original Position in the role, sorted by
  conservative person comparison key. No raw name, condition or evidence is lost.
- `outgoing`, `incoming`: named members absent from / newly present in the other
  group, in the same deterministic order. These are observed directory changes,
  not proof of legal departures or appointment dates.
- `condition_changes`: `(old_position, new_position)` pairs **only for the same
  retained person**, when their condition changes. These pairs never link different
  people. Membership and condition changes may coexist in one event. An unknown
  holder remaining unknown can still produce the legacy `condition_change` kind
  when its condition changes, but `condition_changes` stays empty: no retained
  person's identity is established by `person=None`.

One outgoing and one incoming member produce `possible_replacement`, even if
others remain. For example Ana+Beatriz → Beatriz+Carla exposes outgoing Ana,
incoming Carla and both complete groups. Any other named membership change
(additions only, removals only, or multiple outgoing/incoming) produces the new
`membership_change` kind. **There are no replacement pairings** for those groups:
clients must display outgoing/incoming groups without zipping or sorting into
pairs. If membership is unchanged, kind is `condition_change` if needed,
otherwise `no_change`. Initial named observations and explicit unknown-holder
transitions retain `first_observation` / `possible_departure_successor_unknown`
classifications; unknown is never included in named outgoing/incoming tuples.

A missing entire role remains `review_required`, with its full before group but
**empty outgoing/incoming tuples**: role absence does not prove departures. Revision,
source, chronology and invalid-capture baseline rules remain unchanged. Single-holder
inputs retain their prior classification and before/after behavior. The event's
new fields extend its serialized shape; group-aware consumers must read them.

A newly named role is independently a first observation, not paired with the
missing role. Evidence-only changes/reordering do not change classification.
`no_change` is explicitly returned for each unchanged role group, not a replacement
proposal. These classifications are not a fixed list of supported people.

## Conservative decoding

Original evidence/names are never rewritten. Only comparison keys may repair a
single reversible UTF-8 byte sequence decoded as Latin-1 or Windows-1252.
Repair requires a suspicious `Ã`, `Â` or `â` marker, an exact reverse round trip,
and exactly one distinct, marker-free candidate across those two codecs.
Thus `María Ejemplo` and `MarÃ­a Ejemplo` do not produce a replacement in either
direction. Ordinary accented names are not arbitrarily converted. Replacement
characters, unresolved marker-bearing names and multilayer corruption reject the
whole capture, protecting the baseline. This intentionally favors review: even a
legitimate marker-bearing name can be rejected. It is not a universal encoding
or person-identity detector; adapters must declare ambiguous unsupported input
invalid rather than guess. No lossy cleaning may precede evidence retention.

## Serialized boundary for dependent tasks

There is no serializer or persistence framework in T1. A wire representation uses
objects with the exact dataclass field names above, tuples as arrays, enum
`.value` strings, aware datetimes as ISO-8601 strings with offsets, and Python
`None` as JSON `null`. `dataclasses.asdict` yields nested field dictionaries;
callers still encode datetime values explicitly. `effective_date` is a dataclass
field and remains null. Round-trip loaders must reconstruct datetimes, enums,
positions and immutable tuples before qualification. Evidence strings remain
unaltered. Retain the prior full observation (including its source/revision) in
history; individual event objects are not substitutes for the observation log.

## Verification and scope

Run offline, without importing the legacy monitor:

```sh
PYTHONDONTWRITEBYTECODE=1 python3 -m unittest discover -s scripts/website_monitor_pro/tests -p 'test_pep_directory.py' -v
```

The pure comparison covers structured role/group identity, rejection, encoding,
classifications, raw evidence/condition retention and latest qualified observations
without Gemini. Offline DAB/IBC/UMSA adapters and their capture-quality checks
are now included below; capture acquisition and saved production replay remain
future work. S7 durable holder history awaits
persistence. S8 runtime shadow isolation awaits monitor integration. No existing
code path changed, so there is no meaningful shared legacy differential command;
unsafe legacy tests and broader Python/PHP suites await isolated integration.

## Offline pilot adapters (T2)

`scripts/website_monitor_pro/pep_directory_extractors.py` exposes:

```python
Capture(status, source_url, observed_at, expected_positions, encoding)
extract(profile, body, capture) -> Observation
```

`profile` is exactly `dab`, `ibc` or `umsa`; unknown profiles raise `ValueError`.
`body` is caller-provided `bytes | str`; no network/browser/process, database,
logging, dotenv, monitor or AI is invoked. BeautifulSoup (`bs4`), already a project dependency, and stdlib are used in
addition to the local T1 core. T1 alone remains stdlib-only. The core is unchanged.

The caller must provide the exact verified final source URL, HTTP status 200, an aware
observation timestamp and a **separately reviewed, nonempty frozenset of exact
(unit, base-role) pairs** as `expected_positions`. It is an explicit completeness
attestation, not a list inferred from the current capture, database IDs or an
unverified initial baseline. Neither HTML nor a successful status alone proves
that the server supplied the whole directory. A missing/extra identity rejects
this profile until its inventory is reviewed. Do not automatically update that
inventory to make a rejected capture pass. It must be resolved before activation;
this unit neither persists nor configures runtime inventories.

The accepted URLs are:

- DAB: `https://www.dab.gob.bo/personal-ejecutivo`
- IBC: `https://www.ibc.gob.bo/nomina.php`
- UMSA: `https://www.umsa.bo/web/guest/rectorado`

Outputs use `source=<profile>:directory` and `revision=pilot-html-v2-groups`, independent
of database IDs. This revision deliberately rebaselines any prior v1 adapter input
rather than comparing incompatible extraction semantics. URL aliases/redirects
are not guessed. Always pass results through
T1 `compare(previous_qualified_baseline, observation)`, including results marked
valid: T1 still validates timestamps, person encoding and other shared contracts.
Persist only its qualified next baseline, retaining history separately. Extraction
validity does not supersede T1 validation. No runtime integration is included.

Bytes are decoded **strictly using caller-specified encoding**, never guessed from
HTML, retried with Latin-1 or decoded with replacement. Explicit encoding must come
from capture provenance. Already-decoded text is preserved without re-encoding;
HTML charset declarations cannot recover forensic bytes from that text. Valid
accented names are not repaired. T1 alone repairs reversible mojibake comparison
keys, while retaining original person/evidence values. Decode failures return
`failed` with empty text evidence; callers needing forensic replay must separately
retain original bytes, since T1 evidence is text-only.

The full decoded input is retained exactly in `Observation.evidence` (even for
rejections after decoding). `Position.evidence` is the original selected row/profile
DOM serialized by BeautifulSoup **before field whitespace cleanup**. Serialization
can normalize quotes, entities and void-tag syntax; it is not a byte-exact slice.
Use full observation evidence for exact decoded-source replay.

### Profile boundaries and synthetic fixtures

Only small synthetic HTML fixtures with fictional names/contacts are included.
These fixtures exercise the contract; they do not establish live coverage or
independently attest directory completeness.

| Profile | Selection and accepted boundary |
| --- | --- |
| DAB | `.view-personal-ejecutivo table.views-table`; headers Nro / Nombre Completo / Cargo / Unidad; named cell classes bind unit, role and person in each row. One table is required. Terminal `a.i.` is retained as condition/evidence, not base-role identity. |
| IBC | `#contenido .card`; `.card-header` identifies the unit; `.card-body table.table` has headers Ítem / Nombres y Apellidos / Cargo/Unidad. The final cell must contain role + one `br` + the same unit. Item numbers do not identify roles. |
| UMSA | `#content .journal-content-article .TablaSpeed .filaPersona .col-2b .parte2`; two direct `b` children hold person and office label. Only one Rectorado profile is supported; biography, navigation, portrait and contacts are excluded. |

Distinct holders of the same IBC role are retained as a group. An empty IBC
section is accepted only with an intact table, expected headers, exactly one
tbody, no malformed rows/cells/stray content, and an inventory matching every
expected role. Missing tables/headers/tbody or a missing attested role remain
incomplete; an empty directory still cannot qualify.

### Fail-closed limits

Capture validation precedes association extraction: status/source, blocked/empty
body, one HTML/body envelope with explicit final closing tags, pagination signals,
then unique directory boundaries, expected headers and row/cell relationships.
For DAB and IBC, every `td` within the selected table must belong to an extracted
body row. Orphan cells outside those rows reject the entire capture as
`incomplete`, even if the role inventory still matches; they cannot establish a
holder's departure. The complete decoded evidence and prior baseline are retained.
Truncated envelopes, absent selectors, blank required fields, unexpected empty
tables, unreviewed inventories and missing/extra roles return `incomplete`.
Contact-only email, HTTP/HTTPS/FTP or www URL, and numeric phone values in person
cells also return `incomplete`; no name-shape eligibility test excludes accents,
apostrophes or mononyms. Identical/equivalent occupant duplicates, duplicated
boundaries or conflicting unit context return `ambiguous`. Rejected observations
have no positions. T1 `compare()` retains the identical previous baseline and emits
no events. These checks are conservative pilot heuristics, not universal HTML
validation or a guarantee against a well-formed server-side partial response.

Blank person cells are **not** treated as explicit vacancies; these layouts lack
an attested vacancy marker. No departures are inferred from them. Likewise, unit
renames/new roles require inventory review rather than silent baseline updates.
Only observed terminal `a.i.` is extracted as a separate condition; no invented
interino/suplente synonyms are introduced. Only UMSA's two known labels
`Rector UMSA` and `Rectora UMSA` map to **Rectorado UMSA**; reviewed inventories
must use that canonical role. The exact label remains in original evidence, so a
new holder with a gendered label change produces one possible replacement, not a
new role plus a missing role. No generic gender/title normalization is performed.
Other university roles are outside this single-profile scope. Extra fields, new
pagination or ambiguous occupants still require profile review.
These restrictions favor missed proposals over fabricated departures.

### Offline verification and coverage

Initial delivery validation passed 20 adapter tests and 46 combined tests.
The orphan-cell correction then reproduced failures for both DAB and IBC
(21 tests, two failing subtests, exit 1) before implementation. After correction,
**21 adapter tests** and **47 combined tests** (26 core + 21 adapter) passed,
each with exit 0. The regression preserves intact same-role groups and checks
rejected observations, exact evidence, prior-baseline identity and absent events.
These are synthetic offline tests, not live capture or operational proof.

The adapter tests exercise public `extract()` and T1 `compare()`: associations,
noise/order independence, strict decoding, retained evidence, replacements,
conditions, group transitions, duplicate-equivalent identities, empty sections,
and rejection with baseline preservation. The core suite also protects UTC/DST
chronology and unknown-holder condition changes without identity pairings.
No legacy monitor, database, browser or network was invoked.

Reproduce from the repository root using an existing project environment with
`bs4` (set `PYTHON` to that environment's Python executable; do not install one
as part of this delivery):

```sh
PYTHONDONTWRITEBYTECODE=1 "$PYTHON" -m unittest discover -s scripts/website_monitor_pro/tests -p 'test_pep_directory_extractors.py' -v
PYTHONDONTWRITEBYTECODE=1 "$PYTHON" -m unittest discover -s scripts/website_monitor_pro/tests -p 'test_pep_directory*.py' -v
```

Capture acquisition, source qualification, bindings, persistence, monitor/observer
integration and activation are outside this offline delivery. No live coverage is
claimed, and no shared legacy CLI differential or broader suite was run.


## Dormant static-response observer

The monitor accepts an optional keyword-only injected directory observer. Otherwise,
only an explicit `PEP_DIRECTORY_SHADOW_CONFIG` selector loads shadow configuration;
when absent, no shadow module/configuration is loaded and no shadow connection is
opened. Ordinary monitor construction still creates its legacy DatabaseManager;
this default-off promise does not disable legacy behavior.

A successful HTTP response is frozen before cleaning: immutable body bytes, source,
requested/final URL, status, aware observation timestamp and one declared HTTP
charset. Encoding is not guessed. Only the selected static response is submitted;
PDF, explicit JavaScript, a selected browser fallback and HTTP errors do not submit.
There is no additional fetch. Transport ConnectionError propagation remains intact.
An empty selected static response is observed before the legacy no-content exit.

Configuration requires exact shapes, positive IDs, unique inventories and qualified
profile URL bindings. Capture metadata must match the binding exactly. The
qualification fingerprint contributes to revision identity. Each submission uses a
fresh identity and its own explicitly configured connection with bounded timeouts,
no retries and closure in finally. Credentials are excluded from provenance.
Observer failures emit fixed diagnostic codes, not raw exceptions/configuration.
They must not change legacy snapshots, alerts, statuses or return values.

This seam remains dormant. Configuration examples or unit fixtures do not qualify
real institutions or authorize connection, capture, monitor activation or deployment.
Actual adapter-to-PostgreSQL integration is a separate delivery.

### Offline verification boundary

Never collect monitor tests before isolating imports: the legacy module probes
dotenv and configures logging at import time. Use the dedicated runner with an
existing compatible Python environment and a parent-exported baseline monitor:

```sh
python -B scripts/website_monitor_pro/tests/run_pep_directory_shadow_tests.py --base-monitor BASELINE_MONITOR
```

Export the immediate predecessor's monitor outside tracked test sources; tests do
not read Git history. Launch with a controlled synthetic environment, clearing
inherited DB/logging/timeouts, `PYTEST_ADDOPTS` and `PYTEST_PLUGINS`. The runner hides
.env probes, suppresses dotenv loading, replaces rotating logs, denies external
HTTP/DB/socket/process operations before collection, disables plugins/conftest/cache
and confines evidence writes to its ignored test-evidence directory.

Required existing packages are pytest, requests/urllib3, psycopg2 (including extras),
python-dotenv and BeautifulSoup. No dependency installation or runtime build is
implied. Browser/PDF behavior is mocked; no live browser or PDF coverage is claimed.

The bounded suite covers immutable pre-cleaning capture, default-off/configured/error
paths, charset ambiguity, metadata refusal, qualification revisions, connection
closure and legacy source-run regressions. Its predecessor comparison covers process
calls, capture tuples, CLI stdout/stderr/exit and check-all ordering under
absent/enabled/failing synthetic observers. Only shadow diagnostic messages and
mock-owned identities/time are normalized; legacy effects must remain equivalent.
CLI run/check-all dispatch is mocked but not dynamically compared; its preservation
is supported by unchanged dispatch source, not a dispatch assertion.
The existing chronology, unknown-holder and malformed-table protections remain.

Current isolated candidate verification passed 195 tests and 94 subtests (exit 0),
using the existing Python environment and the immediate predecessor monitor. This
includes mocked legacy differentials, not live capture or database integration.
The exact historical code port uses ordinary verification, not a fabricated new
RED/GREEN cycle. The cohesive observer/seam/tests/docs slice exceeds the advisory
review size to preserve its compatibility proof alongside the shared monitor edit.

Differential tests require the isolated runner and an explicitly exported baseline.
Ordinary collection without that input skips the 85 differential cases; those
skips are not compatibility proof. The isolated run remains required separately.
