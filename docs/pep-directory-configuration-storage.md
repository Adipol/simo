# Configuration storage: transaction-only prerequisite B

The additive public migration stores configurable-directory evidence and invalidates
registered source eligibility when its Fuente changes. It is **not** a rollout,
approval service, Repository gate, or activation.

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
enforced by this slice. They require the later trusted service/Repository boundary.
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
