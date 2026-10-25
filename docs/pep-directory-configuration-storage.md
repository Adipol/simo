# Configuration storage and bounded stored decisions

The additive public migration stores configurable-directory evidence and invalidates
registered source eligibility when its Fuente changes. It is **not** a rollout,
Repository gate or activation. The separate service below decides only over already stored previews.

## Review path

1. Read `2026_10_18_000002_create_pep_directory_configuration_tables.php`.
2. Inspect `ConfigurationSchemaTest.php`: inherited opt-in/target/six-table guards
   run unchanged before the fixture overlay and the public migration.
3. Run only the parent-authorized isolated commands; never scan/replay migrations.

## Storage decisions

| Area | Contract |
|---|---|
| PostgreSQL | Supported engine; SQLite approval is explicitly unsupported |
| History | Four additive tables: configurations, captures, previews, decisions |
| Revision | Full Python `configured-html-v1:<sha>` in varchar(255), never stripped |
| Source | Default-off configurable registration; separate eligibility counter and nullable draft/preview/approval pointers |
| Observation | Nullable actual-used configuration/capture/approval references; no backfill |
| References | Same-source composite FKs, restrictive internal deletion; existing Fuente SET NULL unchanged |
| Uniqueness | One live configurable registration per non-null Fuente |
| Rollback | `down()` refuses destructive automatic rollback; tests use outer transaction rollback only |

History is modeled as append-only evidence, but arbitrary SQL UPDATE immutability,
byte/hash consistency, approval authorization, and lifecycle correctness are not
enforced by the migration. The service below checks stored integrity and decision authorization; trusted ingestion and the Repository operational boundary remain future work.
The lifecycle counter must advance with decision sequence: the trigger inserts
`eligibility_version + 1` while holding the source lock, then advances the counter.
It fails atomically rather than guessing a sequence on inconsistent state.

## Fuente invalidation

`public.pep_fuente_invalidate` invokes a SECURITY INVOKER function with explicit
`pg_catalog, public` search path and qualified application relations. The updating
statement already locks Fuente; matching configurable source rows are locked in
ascending id order. URL/type changes use `IS DISTINCT FROM`; true-to-false active
changes and DELETE also invalidate. Each effective event increments eligibility,
clears approval/current preview, and appends a system-attributed decision. Binding
changes and DELETE additionally clear draft configuration. DELETE records audit
before the existing FK nulls Fuente. Baseline, observation version and evidence
history survive. Metadata-only updates, repeated inactive writes, and reactivation
do not invalidate or restore approval. URL ABA invalidates twice.

The migration requires Fuente URL/type/active columns; their production presence,
defaults and types are not attested here. Only the six-table fixture adds nullable
URL/type and default-true active columns inside the inherited outer transaction;
existing rows get no invented URL/type backfill.

## Proof and limits

The single-connection test is designed to execute migration `up()` and public Fuente SQL
mutations, exercise constraint rejection in nested savepoints, and check inherited
before/after row fingerprints, column lists and exact table inventory. It separately
checks function and trigger absence after rollback. No permanent overlay or DDL is
retained. Queue/HTTP fakes remain inherited and asserted.

PostgreSQL sequence allocations on existing serial fixtures can survive rollback;
these tests allocate synthetic Fuente/source/observation ids. **Sequence preservation
is not proved or claimed.** New qualification sequences are transactional DDL.

V2 remains `unattested_v2` with null function/trigger identity pins. Legacy
extractor code is untouched. Active-user/permission enforcement is deferred;
invalidation uses system identity, not a guessed human actor.

## Current verification

The PHP pure contract suite passed once: PHP 8.5.6 / PHPUnit 11.5.55,
5 methods, 428 assertions, exit 0, empty stderr. Python's pure suite passed once:
5 methods, no failures/errors/skips, exit 0. These are not database proof.

The initial preflight stopped before SQL because the authorized persistent target
was not running; that failure receipt is preserved. After explicit authorization,
the same target was revalidated and started once. One bounded readiness check
passed. A fresh read-only snapshot matched all 38 retained rows and every metadata
field in the earlier review-UI snapshot before any test mutation.

Observed refusal checks, once each with network disabled: absent opt-in skipped
1 test / 0 assertions, exit 0; empty and invalid opt-in each produced 1 setup error /
0 assertions, exit 2. No database setup ran in those refusal cases.

The single PostgreSQL feature run passed: PHP 8.5.6 / PHPUnit 11.5.55,
1 test / 530 assertions, exit 0, 0.174 seconds, empty stderr. It exercised migration
`up()` only inside the inherited transaction, source invalidation and constraint
rejection, then normal rollback. Function/trigger absence was reported after rollback.
The capture wrapper subsequently exited 1 because its summary check expected
`Tests: 1` instead of PHPUnit's successful `OK (1 test, 530 assertions)` format.
That is a receipt-parser failure, not a failing feature test; no test retry occurred.

A separate post-test read-only snapshot exactly matched the fresh pre-test snapshot:
6 tables, 38 rows (6 fuentes, 6 sources, 22 observations, 4 events, no users/reviews),
users/fuentes still id/label only, unchanged constraints/indexes/relations/functions/
triggers/extensions/schemas, and no retained authorization or qualification objects.
Sequence values were neither compared nor reset. All commands were bounded to
30 seconds, using existing images/vendor and ephemeral read-only PHP containers.
No reinstall, durable qualification installation, other target, or diagnostic access
occurred. Local receipts preserve both the initial failure and resumed execution.

Later required: independent review, separately authorized permanent
owned-target rollout, real multi-connection blocking/deadlock tests, approval and
Repository lifecycle integration, trusted bridge/panel, and operational authorization.
Uncommitted DDL on one connection cannot prove concurrency safety.

## SQLite test-discovery correction

Initial CI `40046036880` failed in SQLite: unrelated `RefreshDatabase` tests
(such as `ResultadosPendingCountTest`) discover all public migrations, so the
engine rejection aborted their setup. PostgreSQL and Python CI jobs passed.
This was global migration discovery, not an attempted qualification approval.

`up()` now returns before DDL only for the conjunction of SQLite, Laravel's
`runningUnitTests()` (application environment `testing`), and the
`PHPUNIT_COMPOSER_INSTALL` marker. For example, PHPUnit SQLite setup omits this
migration; SQLite migration execution without the marker still throws the exact
unsupported-engine exception. All other non-PostgreSQL contexts still reject.
The PostgreSQL DDL/trigger body and refusing `down()` are byte-for-byte unchanged.

Laravel may mark this test-only omission as migrated **without installing any
qualification objects**. It is not V2 attestation, SQLite operational support,
or a deployment path. Test environment alone does not enable the exception.

Observed PHP 8.5.6 / PHPUnit 11.5.55 verification, each command once:

- RED: 1 test / 5 assertions / 1 failure, exit 1, showing the original rejection
  instead of the required test-only return; GREEN: 1 test / 5 assertions, exit 0.
- Compatibility suite: 6 tests / 45 assertions, exit 0. Public `up()` / `down()`
  probes use replaced database/schema facades and isolated child processes to
  exercise absent-marker, non-testing, unsupported-driver and refusal cases.
- Pure schema-contract suite: 5 tests / 428 assertions, exit 0; portable helper,
  contract fixture and validator pins unchanged.
- Base/candidate differential: 18 public entry-point contexts, exactly one
  intentional difference (testing SQLite with marker); 17 unchanged outcomes.
  PostgreSQL reaches only a mocked first-Schema sentinel: **not integration proof**.

All runtime commands used the existing immutable PHP image, network disabled,
read-only checkout/vendor and bounded `/tmp` tmpfs; all had empty stderr and a
30-second outer limit. No DB connection, SQLite engine execution, project
bootstrap, Artisan, installation, persistent-target mutation or CI rerun occurred.
The earlier transaction proof above is historical evidence, not newly rerun here;
full SQLite migration-discovery CI remains to be verified on the corrected candidate.

## Stored decision public contract


`ConfigurationService::approve(sourceId, previewId, actor, decision)` and
`revoke(sourceId, actor, decision)` return a read-only configuration decision.
`ConfigurationDecisionData::fromArray()` accepts exactly `expected_version` (strict
nonnegative integer below PHP_INT_MAX) and nonblank UTF-8 `reason` (max 10000,
no NUL). There is no actor/proof/configuration/result/inventory authority in input.
No source registration, drafts, capture/preview ingestion, compiler or lifecycle API
is exposed. These are configuration decisions, not person-event reviews.

Each transaction discovers the binding without a lock, locks a live Fuente first
when present, then the source, revalidates the binding, then reloads/locks the actor
on the service connection. A changed binding conflicts without locking a different
Fuente after the source. The persisted active user must actually have `gestionar
fuentes`; caller attributes, relations and connection are not authority. Following
ReviewService, each decision clears Spatie's permission cache: this is a bounded
**nontransactional shared-cache side effect**, including failed decisions, not a
production cache/concurrent permission-grant consistency guarantee.

Approval needs configurable registration, exact eligibility version, active live
binding, current preview, matching draft/configuration/capture/source, current
preview generation, URLs, and internally consistent stored bytes/hash/wire identity.
C1 requires the live Fuente URL, configuration binding/directory URLs and capture
URL to match exactly; redirected captures are not supported by this contract.
The full Python revision is retained and checked against the stored canonical bytes;
PHP does not reconstruct canonical JSON or parse HTML. Stored declaration fields
are compared with the stored identity, not compiled. Captured time/status/URL/
encoding, decoded body evidence, result wire and position shape must agree.

**Trust boundary:** hashes prove integrity, NOT trusted origin. C1 assumes the stored
preview came from the existing configured extractor via trusted server ingestion.
There is deliberately no invented producer marker or client trusted boolean.
The actual extractor returns no positions on structural rejection; a complete parse
without reviewed inventory has `incomplete` WITH positions. C1 accepts that structural
candidate for the human's association approval; `valid` alone is insufficient and
empty/failed/blocked/ambiguous candidates cannot qualify. Arbitrary `qualification`
JSON is not an authority flag. Human approval is distinct from parser validity and
is not a claim of exhaustive inventory or legal tenure. Future trusted ingestion/
structural qualification must preserve this boundary; **no public ingestion exists
now**. Direct SQL can still forge evidence, and C1 cannot discover a dishonest
trusted producer. Test fixture provenance is in `configuration-service/README.md`.

Approval inserts one complete immutable server-attributed receipt and advances
eligibility/projection atomically; it preserves the observation version, baseline
and history. A PostgreSQL sequence reserves the server ID before insertion so the
receipt can reference its own new approval without later editing audit history.
The database default supplies the decision time. This timestamp is PostgreSQL
transaction time, including when the test runs inside an outer transaction.
Reapproval requires a new current-generation stored preview and a new receipt;
old lineage is never reused. The approver may also be the configurator.

Revocation works for pending, approved, inactive and orphaned registrations. It
clears approval/current preview, advances eligibility, and appends audit in the same
transaction, never restoring old approval/baseline. Fresh repeated intent may advance
the counter; an exact stale retry conflicts. Draft configuration is retained unless
Fuente invalidation already cleared it. No automatic rebind occurs.

The four new read models reject instance save/delete (including quiet variants),
like existing directory models. Guarded mass assignment rejects instance updates;
raw/bulk SQL is explicitly NOT an immutability guarantee. Source modifications are
additive casts/relationships only; `withHolderHistory` behavior is preserved.

## Current stored-decision verification

Eleven code/fixture files are byte-for-byte exports of historical `dce8216`;
the two documentation files retain cumulative evidence and sanitize historical
transcript references. This exact port has no meaningful new RED; no behavior fix
was introduced. The portable contract helper, SQLite migration guard,
compatibility tests, contract fixture and validator pins remain unchanged.

The inherited harness was reviewed before execution. A private adapter changes
only its target literal. The outer transaction starts before authorization,
Fuente columns and qualification `up()`; the selected tests do not commit or call
`down()`. HTTP and queue fakes remain enabled.

Fresh read-only verification of the authorized running persistent target matched
the latest qualification verifier's full post-test snapshot: all 38 rows across
six tables, users/fuentes still id/label only, and identical columns, constraints,
indexes, relations, routines, triggers, extensions and schemas. No authorization
or qualification objects were retained.

The initial absent-opt-in command exited 125 before PHP: Docker could not create
the missing vendor mountpoint in the read-only candidate. That failure and its
separate stdout/stderr/exit receipts remain preserved; it was not a skipped test.
Execution stopped until explicit authorization to correct the mount and resume.
Only an empty ignored vendor directory was created, without copying dependencies,
installing files or introducing a symlink. The existing root vendor remained a
read-only nested mount, following the successful qualification runtime setup.

After fresh identity/isolation checks, a new full read-only snapshot matched both
the qualification verifier and the earlier stopped-run snapshot. Each authorized
resumed check ran once on PHP 8.5.6 / PHPUnit 11.5.55, with empty stderr:

- Absent opt-in, network disabled: 8 tests / 0 assertions / 8 skips, exit 0.
- Empty opt-in, network disabled: 8 tests / 0 assertions / 8 setup errors, exit 2.
- Invalid opt-in, network disabled: 8 tests / 0 assertions / 8 setup errors, exit 2.
- Positive PostgreSQL service suite: 8 tests / 705 assertions, exit 0, 0.998 seconds.
  All eight tests reported function/trigger absence after rollback.

A separate post-test read-only snapshot exactly matched the entire fresh pre-test
state: six tables and all 38 rows, columns, constraints, indexes, relations,
routines, triggers, extensions and schemas. No authorization or qualification
objects remained. Sequence values were neither compared nor reset. All commands
were bounded to at most 30 seconds, using the existing immutable PHP image,
read-only candidate/vendor/adapter, tmpfs and the isolated target's network
namespace for the positive run only. No target start, reset, installation, build,
download, durable DDL or other database access occurred. Source exports, all seven
protected files and the original failure receipts remained unchanged.

The service tests exercised public approval/revocation, persisted permission
checks, stale intent, stored integrity, immutable model hooks and injected atomic
faults. Shared production cache, concurrent permission changes, multi-connection
blocking/deadlocks and trusted producer provenance are not proved. Hash equality
is not producer attestation. Independent verification and publication remain the
parent's responsibility. No business policy or operational authority changes.
