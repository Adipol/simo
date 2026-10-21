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

Identity is exactly `(source, unit, role)`. No case folding, fuzzy role/person
matching, alias inference or cross-unit pairing is performed. Duplicate
`(unit, role)` positions reject the whole observation, even with identical names.
A present role with `person=None` differs from a missing role.

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
`uncertain_person_encoding`, `source_mismatch`, or `older_observation`.
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
`previous_evidence`/`current_evidence`, and `effective_date=None`.
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

A newly named role is independently a first observation, not paired with the
missing role. Evidence-only changes/reordering do not change classification.
`no_change` is explicitly returned for each unchanged position, not a replacement
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

T1 covers the pure portions of S3–S5 and S7–S9: structured identity, rejection,
encoding, classifications, raw evidence/condition retention and latest qualified
observations without Gemini. S9's actual DAB/IBC/UMSA extraction, capture-quality
checks and saved pilot replay await adapters. S7 durable holder history awaits
persistence. S8 runtime shadow isolation awaits monitor integration. No existing
code path changed, so there is no meaningful shared legacy differential command;
unsafe legacy tests and broader Python/PHP suites await isolated integration.
