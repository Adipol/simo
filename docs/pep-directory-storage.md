# PEP directory shadow storage

Four additive tables and an **unwired PostgreSQL repository** support the wire
contract in [pep-directory-transitions.md](pep-directory-transitions.md).
This delivery adds a lossless codec and atomic persistence, but no model, review
service, UI or monitor integration.
Original databases, retained authority tables, cambios and Scraper are untouched.
No data, migration-ledger entries, source activation or permissions are seeded.

## Storage contract

| Table | Contract |
| --- | --- |
| `pep_directory_sources` | Unique stable `identity`, nullable live `fuente_id`, qualified `baseline_observation_id`, source `version` initially 0. |
| `pep_directory_observations` | Full Observation `payload`, offset-preserving `observed_at` string, capture identity, submission ID, non-unique SHA256, provenance, disposition/reasons, server-default receipt time. |
| `pep_directory_events` | Full Event `payload`, previous/current observation references, deterministic event index, review-state projection (`pending`, `confirmed`, `discarded`) and version initially 0. |
| `pep_directory_reviews` | Decision version, event reference, nullable live user link, immutable actor identity/snapshot, server-default time, reason, prior/new state. |

Observation history **is** holder history; no generic tenure/config subsystem is
introduced. JSON retains complete groups, outgoing/incoming sets, same-person
condition pairs, Unicode, nulls, original evidence and ISO timestamps with offsets.
The Event's `effective_date` remains JSON null unless documented; detection time
is not an appointment/departure date. PostgreSQL timestamps alone would lose the
original offset, so authoritative wire dates remain inside the full payload.
No duplicated SQL effective-date projection is maintained.

Submission uniqueness is `(source_id, submission_id)`, **not content hash**.
Distinct unchanged captures remain storable. `(current_observation_id,event_index)`
identifies a classification within one ordered comparison result. The writer
uses the index in the core's deterministic event order, omitting `no_change` rows.
`(event_id,version)` rejects duplicate review versions; it does not implement a
concurrency protocol. Source identity and same-source observation keys are unique.
The event source/review-state index supports the planned source-scoped review list.

Live fuente/user deletion uses SET NULL, never cascades or restricts legacy parent
deletion. Stable source identity and actor snapshots remain. Internal links restrict
evidence deletion; composite FKs prevent cross-source baselines and event inputs.
The nullable prior-observation link permits events without a predecessor; the
current observation is required. The schema does not validate JSON against the
Python dataclasses or reconcile payload fields with relational references.

The Python writer locks the source row, reconstructs immutable inputs and compares
using the existing engine. It atomically appends full observations, events and
baseline/version updates. Rejected captures/reasons are retained without advancing
the baseline; accepted unchanged captures produce no `no_change` alerts. Repeated
submission verifies the stored original request instead of overwriting it.

Append-only observations/events/reviews and immutable identity/payload fields are
**application contracts**, not update-blocking database triggers. Live links and
projections are intentionally mutable. The future review service must authorize
an active user with `gestionar fuentes` on every mutation, lock/check versions,
append the decision and update its projection atomically. Schema alone authorizes
nobody. Server-default review times must not be replaced with client timestamps.
Sequence IDs can have gaps after rejected inserts; version counters must not drift.

## Isolated validation contract

Positive checks require a newly provisioned, independently owned empty PostgreSQL
target, never an existing application or diagnostic database. The harness pins
`pep_schema_20261018_d83b91` at `127.0.0.1:5432`; that name alone is not ownership
or permission. Use a separate PostgreSQL container with no external network,
published ports or persistent host data, and let the PHP test container share only
its isolated network namespace. Mount this checkout at `/work` read-only, with
compatible Composer dependencies already present. Do not install dependencies or
run ordinary application bootstrap to execute these checks.

The pilot-only harness constructs a minimal Application without project bootstrap,
dotenv, configuration files, cached configuration or project providers. It pins
cache/environment paths before construction, disables Gemini, fakes queues/HTTP,
and checks effective default/migration/transaction identities before a connector.
It verifies the selected database and refuses any nonempty target before mutation.
Only synthetic users/fuentes parents and the named shadow migration execute through
public `up()` methods within a transaction rolled back after every test. A scoped
`down()`/`up()` case exercises populated shadow tables while preserving synthetic
parent records. No global migration scan, legacy ledger replay, migrate:fresh,
blanket cleanup or original data is used.

`PEP_SCHEMA_ENGINE` absent skips before setup; an explicitly empty or unsupported
value fails before Application creation. `pgsql` selects the guarded path. The
pure opt-in test intercepts the Application boundary and checks unchanged process
variables. Skipped discovery is not schema verification. The historical refusal
message mentioning S13 is retained for compatibility, not as execution authority.

Inside the isolated PHP runtime, with configuration disabled:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit/PepDirectory
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/PepDirectory/ShadowSchemaTest.php
PEP_SCHEMA_ENGINE=pgsql php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/PepDirectory/ShadowSchemaTest.php
```

The second command deliberately demonstrates no-opt-in skips; only the third
exercises PostgreSQL. Independently verify target identity, isolation and empty
public-table inventory before and after the positive run. Do not rerun against a
populated target or treat rejection as permission to wipe it.

The schema checks alone establish neither repository behavior nor concurrency.
No SQLite storage support, production migration compatibility, UI or operational
readiness is claimed. Repository verification is separate, as described below. This cohesive
migration/harness/tests/contract slice exceeds the advisory review size so that its
safety prerequisites and public assertions remain together.

### Prior schema delivery checks

Using existing PHP 8.5.6 and PostgreSQL 17.9 runtimes, the delivered candidate
passed the 48-test pure prerequisite suite (130 assertions, exit 0). Ordinary
schema discovery produced seven explicit skips and zero assertions (exit 0).
The separately authorized PostgreSQL run passed seven tests and 59 assertions
(exit 0), with zero public tables before and after. Only newly provisioned
synthetic test state was used. Existing application/diagnostic databases were not
used. This is an exact-code delivery with fresh ordinary verification, not a new
behavior change or a claimed new RED/GREEN cycle.


## Atomic repository and lossless codec

`pep_directory_codec.py` encodes/decodes explicit v1 Observation/Event objects.
It rejects extra/missing keys, invalid types, naive or noncanonical timestamps,
invalid enum values and non-null effective dates. Full evidence and groups remain
in JSON. It performs no I/O and does not introduce comparison or identity rules.

`pep_directory_storage.Repository` receives an exclusively owned PostgreSQL
connection. Each public method requires an idle, open, non-autocommit connection;
it refuses caller transactions instead of committing or rolling them back.
There are no environment, driver or monitor imports in the repository.

`submit(observation, submission_id=..., capture_identity=..., provenance=...,
fuente_id=None)` validates and freezes the request before opening its transaction.
Provenance must be actual JSON types, with string keys, finite numbers and valid
UTF-8; coercion of tuples, boolean IDs or non-string keys is rejected. Source,
submission and capture identities have bounded lengths. The source row is locked
before reading its baseline. A new source uses conflict-safe creation.

The transaction stores the observation, receipt metadata and classifications,
then advances baseline/version only for accepted observations. Any failure rolls
back the transaction. Repeated identical submissions return the original receipt;
a changed request under the same source/submission ID raises `submission conflict`.
JSON comparison is type-sensitive (for example, `1` differs from `true`).
`history(source)` returns original receipts in append-ID order; `state(source)`
returns the current baseline/version or None. These are evidence, not tenure claims.

## Retained synthetic storage verification

After empty-schema checks are complete, and only in an explicitly authorized,
independently verified synthetic PostgreSQL namespace, install the test schema
once inside the isolated PHP runtime:

```sh
PEP_SCHEMA_ENGINE=pgsql php tests/Integration/PepDirectory/prepare_storage_schema.php install
```

This invokes only the two named migrations and commits six empty tables, without
a migration ledger. An existing table inventory is refused; never clear a target
to make this installer pass. The test-only parent tables have no initial records.
Keep the container identity, ownership, image and isolation pinned externally;
the hardcoded database name is not evidence of ownership.

Using the pinned Python test runtime with a read-only checkout:

```sh
python -m unittest discover -s scripts/website_monitor_pro/tests -p test_pep_directory_codec.py
python -m unittest discover -s scripts/website_monitor_pro/tests -p test_pep_directory_storage.py
PEP_STORAGE_ENGINE=pgsql python -m unittest discover -s scripts/website_monitor_pro/tests -p test_pep_directory_storage.py
```

The second command skips before driver import/connection. The positive command
requires exactly the six prepared tables and verifies database/user identity.
Each suite run uses a fresh UUID namespace. Tests retain committed synthetic
observations and events; teardown closes connections, without deleting fixtures.
Run parent and independent verification serially because rollback checks compare
database-wide counts. Do not rerun the installer between them. Preserve the
populated target for subsequent review tests; empty-schema tests need a separate
owned empty target if they must be repeated.

Checks cover retry conflicts, full payload round trips, rejected/unchanged
captures, revision boundaries, invalid requests and caller transactions, injected
failure rollback, simultaneous creation/retry and ordered competing captures.
The repository is not wired into any producer. No authorization domain, live
capture, production migration, monitor activation or deployment is included.

### Current repository delivery checks

The existing Python runtimes passed 26 core and five codec tests; combined
directory discovery passed 52 tests with one explicit storage-suite opt-out skip
(exit 0). The separate authorized PostgreSQL run installed the six-table schema
once into the verified empty synthetic target and passed all eight storage tests
(exit 0), including real concurrency and injected rollback checks. Fixtures remain
for serial independent verification. Two earlier test-launch failures were caused
by selecting BeautifulSoup adapters in a minimal image and resolving a virtualenv
symlink to the system interpreter; corrected direct virtualenv execution passed
without installing dependencies. Neither failed launch reached the database.

This is an exact historical code port with fresh verification, not a newly claimed
RED/GREEN cycle. The cohesive repository/codec/tests/docs slice exceeds the advisory
review size so transaction behavior and its proof stay together.

### Test-runner compatibility correction

The first CI run exposed a fixture-import collection error under pytest, reproduced
locally with exit 2 before database access. Storage tests now select a relative
fixture import when loaded as a package, retaining the standalone import used by
unittest discovery. No production or transaction code changed. Focused pytest
collection now reports eight explicit opt-out skips; the full website suite passes
175 tests with eight skips and 94 subtests. Unittest directory discovery passes
52 tests with one suite-level opt-out skip. These skips are not database proof;
the separately executed eight-test PostgreSQL verification remains distinct.
