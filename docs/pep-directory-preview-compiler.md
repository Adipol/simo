# Offline directory preview compiler

## Purpose and boundary

`pep_directory_preview.compile_preview(envelope)` returns a detached JSON-ready
artifact, deterministically, without mutating input. It reuses `DirectoryConfig`,
`Capture`, `extract_configured` and `encode_observation`, unchanged. This is an
**unreviewed preview**, not approval, transport attestation or an operational
baseline. No inventory is accepted: the extractor receives an empty frozenset.
A complete parse retains all positions with `incomplete`. Structural rejection
retains no positions; no partial success or inferred vacancies are manufactured.

This producer performs no HTTP, database, persistence, subprocess invocation,
application bridging or filename loading. URLs are metadata, never fetch targets.
Exact final source URL equality with the declaration is required; this excludes
redirects and is **not SSRF protection**. Status and observation time are supplied
metadata, not verified transport facts. Nothing here establishes application
Python availability, runtime integration, trusted origin or approval authority.

## Input and CLI

With the existing extraction dependencies available, run:

```sh
python scripts/website_monitor_pro/pep_directory_preview.py --help
python scripts/website_monitor_pro/pep_directory_preview.py
```

The second command reads exactly one UTF-8 JSON object from stdin until EOF and
emits one JSON artifact plus newline on stdout. Paste this synthetic diagnostic
request and close stdin (the body is empty):

```json
{"declaration":{"source":"synthetic","url":"https://example.invalid/directory","directory":"table","record":"tr","columns":2,"fields":{"unit":{"constant":"North"},"role":{"column":0},"person":{"column":1}}},"body_base64":"","metadata":{"status":200,"source_url":"https://example.invalid/directory","observed_at":"2026-10-18T12:00:00Z","encoding":"utf-8"}}
```

For HTML use standard canonical padded base64 of the **original bytes**, not
re-encoded parser output. Public Python callers pass the same plain dictionary:
`artifact = compile_preview(envelope)` (module directory on their import path).
No file paths or other arguments are supported except standalone `--help`.

Only the shown envelope/metadata keys are allowed. Declaration validation is
exactly `DirectoryConfig.from_dict`, with its existing selector/field limits.
Status is an integer 100–599 (not bool); time is an aware Python ISO datetime;
encoding is a registered Python text codec name, 1–64 ASCII letters, digits,
underscores, periods or hyphens. Final URL must exactly equal declaration URL.
Unknown keys, reviewed/expected inventories, approval/approver fields, duplicate
JSON keys, nonfinite numbers, malformed Unicode, NUL strings, invalid/noncanonical
base64, invalid encodings, bad types and naive timestamps reject the request.

## Artifact and byte compatibility

- `format_version: 1` is serialization only, never a trusted-origin marker.
- `configuration`: original `declaration`, actual Python canonical UTF-8 bytes as
  `canonical_base64`, full `configured-html-v1:<sha256>` revision.
- `capture`: original `body_base64`, lowercase hex `content_sha256`, `metadata`.
- `preview`: codec `wire`, exact ASCII JSON result bytes as `result_base64`,
  lowercase hex `result_sha256`, and the codec's `validity`.

Result bytes use `json.dumps(wire, ensure_ascii=True, separators=(',', ':'))`,
without sorting. Compatibility is checked against the frozen synthetic result
hash in the Python tests.
Hashes cover decoded binary bytes, never base64 text. No PHP canonical recreation
is required. Metadata time equals the codec wire time: `Z` becomes `+00:00`,
while the instant and supplied offset are preserved. Body/person changes alter
capture/result identities, not config revision; mapping changes alter revision.
No database IDs, user attribution, approval or operational-eligibility flags exist.

## Errors and limits

Malformed configuration/capture data or serialization overflow raises a sanitized
`ValueError` in the API. CLI exit 2 emits no stdout and exactly
`preview: invalid input or artifact limit` plus newline on stderr. No traceback,
body, token or caller value is echoed. Valid envelopes with failed decoding,
non-200 status, blocked, empty, ambiguous or incomplete extraction can emit
**diagnostic artifacts**; they never grant qualification. Unrepresentable codec
values are data errors, not silently truncated evidence.

Input is at most 2,097,152 bytes (CLI raw bytes and API compact ASCII JSON), raw
body 1,048,576 bytes, nesting depth 16, and serialized output 8,388,608 bytes
including newline. The complete output is checked before any artifact stdout
write. Oversize output rejects the whole artifact; no holder/evidence is dropped.
An output-device write failure cannot be made atomic by this preflight.
Existing extractor DOM/tag/depth/record/evidence budgets still apply; these are
not CPU or wall-clock sandbox guarantees. Use external isolation for hostile
inputs. Exit 0 means artifact emitted only (or help), not successful extraction.

## Delivered scope and verification boundary

This unit adds only the offline Python compiler, its synthetic API/CLI tests and
this guide. Existing core, codec, configurable extraction and orphan-cell fixes
are preserved. It adds no schema, database, migration, PHP integration, review,
qualification, trusted ingestion or activation behavior.

## Observed candidate checks

Using an existing Python 3.14.8 virtual environment (Beautiful Soup 4.14.3,
Soup Sieve 2.8.4, lxml 6.1.1), without installs or network access:

- Focused `test_pep_directory_preview.py` unittest discovery: 6 methods, exit 0.
- Explicit combined suite: 69 methods, exit 0; module load order was core,
  codec, extractors, configurable, preview (26/5/21/11/6 methods). Core must load
  first because its tests replace the core module in `sys.modules`.
- Both suites ran once with no failures, errors or skips. Unittest's normal
  progress/report output was captured on stderr; it is not an error diagnostic.
- Public `--help`: exit 0, help on stdout, empty stderr.
- The synthetic empty-body request above: exit 0, exactly one JSON newline,
  `empty` validity, no positions or approval fields, empty stderr.
- `{}`: exit 2, empty stdout, exactly the documented sanitized stderr line.

The two Python files are byte-exact historical exports, so there is no meaningful
new pre-implementation RED test or new behavior fix. Existing dependencies,
including the orphan-cell fix, portable schema-test helper, SQLite testing guard,
runtime pins and approval/revocation delivery, remain unchanged by this unit.
No database was accessed: preservation here means no access, not a fresh database
snapshot or proof of retained data health. No PHP integration, network transport,
monitor activation, fresh runtime build or operational acceptance was tested.
