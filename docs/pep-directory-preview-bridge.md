# Bounded offline preview bridge

## Boundary

This unit adds an **unused**, server-owned compiler adapter and strict decoder.
It adds no registration, capture, publication, persistence, approval, HTTP entry
point or dependency wiring. Existing compiler, C1, schema, guards, Repository,
monitor, routes and UI remain unchanged. `ui_enabled` remains literal `false`.
This is an offline preview boundary, not activation or application readiness.

`PreviewCompiler::compile(array $declaration, string $rawBody,
array $captureMetadata): CompiledPreviewData` accepts internal server inputs only.
`CompiledPreviewData::fromOutput()` is for controlled-process output, **never
browser artifact ingestion**. A DTO or hash proves neither origin nor approval.
Unknown approval/provenance flags are rejected. Format 1 from this producer
cannot claim `valid`: the compiler supplies no reviewed inventory. Failed,
blocked, empty, ambiguous and incomplete artifacts are diagnostic data only.

## Configuration and invocation

Optional `pep_directory.preview_interpreter` reads
`PEP_DIRECTORY_PREVIEW_INTERPRETER` in config only; default is unavailable.
A future server composition root must explicitly supply that absolute interpreter
to `ProcessPreviewCompiler`. No discovery, fallback or installation is performed.
Neither executable, script, cwd nor injected runner can be selected by a browser.
The internal runner closure is a unit-test seam, not request authority.

Production argv is exactly `[server interpreter, '-E', '-s', fixed project
scripts/website_monitor_pro/pep_directory_preview.py]`; cwd is the project root.
Arguments use `proc_open`'s array form, not shell interpolation. `-E/-s` suppress
Python environment/user-site import overrides while retaining sibling imports.
The child inherits the server environment; these flags do not clear it or restrict
filesystem, network or process privileges. The interpreter and argv are trusted,
server-owned inputs. This is **not an OS sandbox**. The trusted compiler must not spawn descendants;
there is no process-group, descendant containment or privilege-isolation claim.

## Transport bounds and cleanup

| Resource | Limit |
| --- | --- |
| Serialized stdin | 2 MiB |
| Raw body | 1 MiB |
| Complete stdout | 8 MiB |
| Stderr | 8 KiB, counted and discarded |
| Read/write operation | At most 8 KiB |
| Execution deadline | 10 seconds, monotonic |
| Failure reap allowance | At most 0.5 seconds after direct-child SIGKILL |

Input limits precede child creation. Nonblocking `stream_select` interleaves one
read from each ready output with a bounded stdin write; partial writes are
tracked. Output caps are checked before append. There is no duplicate Symfony
buffer: the installed Symfony pipe reader accumulated output before callbacks,
so it is not used. Whole stdout must finish before decoding; no partial artifact
is returned. Incomplete stdin, I/O errors, overflow and timeout reject the run.

All paths close parent pipe handles. A still-running direct child receives
SIGKILL; status is observed before `proc_close`, never intentionally closing a
still-running process with blocking `proc_close`. Cleanup has a separate bounded
reap allowance; failure to observe termination is an error, not success. This
cannot guarantee termination of an unkillable OS task or any descendant.
Actual ordinary synthetic direct-child termination is tested, not universal
kernel/process-tree behavior. If a child remains unreaped, subsequent implicit
PHP process-resource destruction is unverified; the guarded explicit `proc_close`
is not proof of an unconditional total-return deadline.

`proc_get_status` exit observations are retained when `proc_close` subsequently
returns -1. Signal exits use 128 + signal. Transport results retain complete
stdout, discarded-stderr byte count and actual exit; the adapter rejects nonzero
exit without decoding. Every failure has exactly `Preview compilation failed.`
and no previous exception, stderr, argv or input. `compilerExitCode` is internal
diagnostic information, never an instruction to serialize an exception to HTTP.
No retry occurs.

## Exact producer format

The decoder supports the existing `pep_directory_preview.py` format version 1:
configuration declaration plus Python dataclass canonical bytes and full
`configured-html-v1:<sha256>` revision; raw capture bytes, SHA-256 and exact
status/source URL/encoding; and observation wire plus result bytes and SHA-256.
Server timestamps and emitted aware timestamps are compared as UTC instants,
accepting Z or numeric minute offsets and up to six fractional digits. Naive or
mismatched times reject. Wire/capture emitted timestamps must agree exactly.
No extraction or HTML parser is reimplemented in PHP.

Python canonical identity bytes must be compact ASCII-escaped JSON with sorted
object keys. Result bytes must use the producer's fixed observation/position
member order, compact ASCII-escaped JSON and ordinary integer spelling. Lexical
checks reject whitespace changes, reordered identity keys, reordered wire keys,
non-producer string escapes, negative zero and floating/nonfinite numbers even
when hashes have been recomputed. They validate existing bytes, not generate a
competing PHP canonical identity. JSON parsing also rejects duplicates (including
escaped duplicate names), trailing data, unknown keys, wrong types and
noncanonical base64. Object member order in the outer artifact is immaterial.
Hashes establish integrity only, never transport origin or capture qualification.

## Consolidated proof matrix

| Evidence | Established | Not established |
| --- | --- | --- |
| Real isolated PHP children | Successful echo/nonzero exit, exact caps, overflow rejection, early stdin closure, duplex input/output without deadlock, finite active-work timeout with direct-child exit 137 | Python availability/imports, arbitrary OS failures, descendants, OS sandbox or global peak-memory benchmark |
| Fake adapter runner | Fixed argv/stdin/cwd/deadline, preflight before runner, no retry, sanitized exceptions, no decoding on nonzero exit | Real PHP-to-Python execution or capture origin |
| Historical synthetic artifact decoded by PHP | Compatibility with the committed incomplete artifact and its byte/hash identities | Fresh Python execution, artifact provenance, complete directories, all encodings/layouts or qualification |
| Same-runtime PHP → Python / application readiness | **UNVERIFIED; not executed or authorized by this unit** | No application-runtime or transport-origin attestation |

Coverage is partial synthetic serialization/diagnostic compatibility; no new
source-specific regression run. No DB, DDL, application bootstrap, original data,
dependency or runtime changes are included. No database access is performed;
this is not new database preservation or persistence proof. Source registration,
confirmation, eligibility lifecycle and permissions remain outside this unit.
Independent process-boundary review is required before acceptance. No activation.

## Current bounded verification

Using the existing immutable PHP image, with a read-only checkout and dependency
mount, no network, dropped capabilities, and a 30-second outer deadline per suite:

- Real PHP transport: **5 tests, 34 assertions**, exit 0; empty stderr.
- Closure-fake compiler and decoder: **13 tests, 183 assertions**, exit 0;
  empty stderr.
- Runtime: PHP 8.5.6, PHPUnit 11.5.55. Each suite executed once, without retries.

The code, tests and executable/data fixtures are an exact historical port; these
are functional verification results, not a newly observed failing-test cycle.
No Python runtime, application bootstrap, DB, migration, dependency installation
or operational activation was exercised. Same-runtime PHP-to-Python proof remains
a separate future delivery. Hash preservation is not database-state proof.

## Consolidated decoder and runtime update

The decoder validates large JSON without treating a PCRE failure as successful validation, and rejects escaped duplicate member names. The optional `role_condition_mode` declaration supports explicit `ai_suffix` separation; omission retains the original canonical identity.

The historical verification sections above describe the earlier bridge-only slice. The consolidated candidate additionally passed synthetic real PHP-to-Python preview: 2 tests/20 assertions on the existing combined image. This does not establish capture origin, installed-schema qualification or application readiness. PHP unit checks across decoder, mapping, manual guards, capture transport and schema contract passed 34 tests/824 assertions.
