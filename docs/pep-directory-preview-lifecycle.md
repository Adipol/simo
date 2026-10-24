# Dormant directory preview lifecycle

## Status and ownership

This bounded unit adds internal registration, evidence append and draft publication.
It does **not** implement transport, browser ingestion, routes, UI, fetching, jobs or
Repository gating. Installed V2 remains unattested.
A DTO, provenance label, content hash or compiler result is not transport-origin proof.
Only a future controlled server adapter may construct capture inputs; no public endpoint
exists. Do not expose these methods as browser artifact ingestion.

`DirectoryPreviewService` requires the existing `PreviewCompiler` port. The caller cannot
submit a compiled artifact to any lifecycle API. The existing adapter/decoder owns output
validation and actual Python canonical/result bytes; the service never extracts or
canonicalizes directories in PHP. PostgreSQL bytea parameters use hex transport spelling
to preserve NUL, arbitrary bytes and JSON backslash escapes, not new canonical identities.

## API

- `register(int $fuenteId, User $actor): PepDirectorySource`
- `recordCapture(int $sourceId, ServerCaptureData $capture, User $actor,
  ConfigurationDecisionData $decision): PepDirectoryCapture`
- `publishDraftPreview(int $sourceId, int $captureId, DirectoryMappingData $mapping,
  User $actor, ConfigurationDecisionData $decision): PepDirectoryPreview`

Every attempt reloads an active persisted actor and checks actual `gestionar fuentes`
on the service connection, ignoring caller attributes, loaded relations and connection.
Registration first locks the live Fuente, then looks up/locks its configurable source,
then locks the actor. An existing registration returns its stable server UUID without
another audit. Legacy nonconfigurable sources are never adopted or edited. UUID collision
fails; it cannot alias/rebind an existing source. Missing Fuente fails. Inactive Fuente
may be prepared, but separate configuration approval still requires an active live Fuente.

Existing-source mutations discover the binding, lock Fuente → source, revalidate that
binding, then lock/check the actor. They never lock a different Fuente after a source
lock. An orphan or nonconfigurable source cannot ingest or publish. No automatic rebind.

### Input DTOs

`ServerCaptureData::fromArray()` accepts exactly:

- `raw_body`: original string, at most 1 MiB (including empty diagnostic captures).
- `status`: integer 100–599.
- `requested_url`, `final_url`: HTTP(S), at most 2048 bytes; both must equal the live
  Fuente URL exactly. Redirects are deliberately unsupported.
- `captured_at`: valid aware ISO time, up to microsecond precision. Original spelling
  stays in provenance, the instant stays in `captured_at`; metadata uses Python-compatible
  ISO spelling (e.g. `Z` becomes `+00:00`, zero microseconds omitted).
- `encoding`: bounded encoding token, not a guarantee of decodability.
- `provenance`: exactly `{adapter: <bounded label>}`. This is informational, not attestation.

The service derives SHA-256 from the original bytes. Stored actor identity/snapshot and
URL attribution are server-owned. Unknown approval, claimed actor, qualification or
trusted-flag keys are rejected, including inside provenance. Only explicitly synthetic
adapter doubles are used in these tests.

`DirectoryMappingData::fromArray()` accepts `directory`, `record`, `fields`, optional
`section` and `columns`. Fields are unit/role/person/optional condition with constrained
selectors/scopes, column indices or a unit constant. Selectors/constants are bounded to
512 bytes; columns to 1–32. Source, URL, hashes, authority and provenance are forbidden.
The server builds the declaration source and URL from the locked binding.

## Generation and atomicity protocol

Registration appends a human `register` decision at generation 1. Every explicit capture
appends a human `capture` receipt and advances eligibility generation atomically. Its
reason includes the generated capture ID (the existing receipt schema has no capture FK).
Capture append does **not** approve or revoke; existing approval/current preview remain
until accepted publication or explicit configuration revocation/invalidation. The changed generation
still fences stale work. This is not a runtime eligibility gate: Repository integration
must later enforce its own approved-generation protocol.

Publication has two service-owned transactions:

1. Authorize under the lock order above; require the expected generation. Snapshot the
   exact Fuente and source projections, selected capture bytes/metadata/provenance/time,
   current draft configuration and current preview. Check capture hash and exact URLs.
2. End that transaction and compile through the port using that snapshot, **not latest
   configuration**. No draft, preview or receipt is written before compilation succeeds.
3. Reacquire Fuente → source → actor, recheck permissions/generation and compare the
   entire snapshot. Changed binding, draft, configuration, preview, capture or generation
   conflicts. No replacement with the newest configuration is allowed.
4. Insert or reuse the exact source/revision/canonical-byte configuration with the same
   binding. `CompiledPreviewData` already verifies the incoming declaration against that
   canonical identity; associative field order and omitted versus explicit `record` scope
   do not create another configuration. Reuse preserves the original stored declaration
   and canonical bytes; same-revision/different-byte corruption is rejected.
   Insert a new immutable preview with the **new** eligibility generation, append a
   same-sequence human `publish` decision and atomically set draft/current preview,
   advance eligibility and clear approval. Compilation, insert or projection failure
   leaves no partial lifecycle writes. A failed/stale correction leaves prior approval
   unchanged unless the intervening mutation itself invalidated it.

Corrections reuse the same capture ID, original bytes and capture time. Empty diagnostic
results persist as `qualification.state=unapprovable`; complete unreviewed parses with
positions remain `candidate`, never approval authority. The configuration service separately validates and
approves an eligible stored preview. Observation version, baseline and holder/event/review
history are never rewritten by these APIs.

## Verification boundaries

Tests use the **unchanged** `PepDirectoryConfigurationTestCase`: explicit opt-in and
owned six-table target guard before Application/PDO, fixture-only authorization/DDL
inside the outer transaction, full rollback. They never commit authorization or run
`down()`/cleanup. No original/reference database, normal bootstrap, environment file,
HTTP or PHP→Python runtime probe is used. Query-builder writes do not dispatch model
mutation/application events; tests watch those events and the harness asserts no queue
or HTTP work. Normal model retrieval/query/transaction instrumentation is not suppressed.

The fake compiler synthesizes controlled synthetic artifacts **in test code only** and
passes them through `CompiledPreviewData`. It does not prove Python extraction correctness
or actual child-process execution. A separate assertion consumes the retained actual
Python artifact at `tests/Fixtures/PepDirectory/preview-compiler/artifact.json` unchanged;
that demonstrates wire compatibility only, not origin or the fake producer's provenance.
No new Python artifacts/processes were generated in this unit.

The compiler callback observes transaction level **1** (the test's outer transaction),
not service level 2. This proves the service-owned transaction distinction, **not** lock
release or real cross-connection concurrency. Interleaving callbacks are deterministic
same-connection mutations, not multi-process race proof. Real cross-connection concurrency is not established by this suite.

PostgreSQL sequence allocations are not rolled back or reset. Spatie permission-cache
invalidation is not transactional. The array-cache harness does not prove production
shared-cache behavior; zero shared side effects is not claimed.

### Current bounded verification

One serial run of `DirectoryPreviewServiceTest` on the guarded synthetic PostgreSQL
target passed: **14 tests, 546 assertions, exit 0**, with empty stderr (PHP 8.5.6,
PHPUnit 11.5.55). The absent opt-in run skipped all 14 tests (exit 0); empty and invalid
opt-ins each produced 14 setup errors (exit 2), before Application/PDO setup. Each
invocation ran once, without retries.

Fresh read-only snapshots before and after matched the retained predecessor snapshot
exactly: six tables and all 38 rows, columns, constraints, indexes, relations, routines,
triggers, schemas and extensions. Both parent tables retained only `id` and `label`;
no authorization or qualification overlay remained. This is full-state comparison,
not merely equal counts. The inherited published harness is unchanged; a private
adapter changed only its target literal for this verification.
Permanent schema installation, restart durability, production cache/request integration,
full durable concurrency fixtures and real PHP-to-Python execution are outside this proof.
