# PEP directory shadow storage — schema slice only

Four **unused additive tables** support the wire contract in
[pep-directory-transitions.md](pep-directory-transitions.md). No runtime writer,
model, review service, UI or monitor integration is included. Runtime persistence remains a later delivery.
Original databases, retained authority tables, cambios and Scraper are untouched.
No data, migration-ledger entries, source activation or permissions are seeded.

## Storage contract / next writer seam

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
identifies a classification within one ordered comparison result. The future
writer must define a stable index from the core's deterministic event order.
`(event_id,version)` rejects duplicate review versions; it does not implement a
concurrency protocol. Source identity and same-source observation keys are unique.
The event source/review-state index supports the planned source-scoped review list.

Live fuente/user deletion uses SET NULL, never cascades or restricts legacy parent
deletion. Stable source identity and actor snapshots remain. Internal links restrict
evidence deletion; composite FKs prevent cross-source baselines and event inputs.
The nullable prior-observation link permits events without a predecessor; the
current observation is required. The schema does not validate JSON against the
Python dataclasses or reconcile payload fields with relational references.

The next Python writer must lock the source row, reconstruct immutable inputs and
qualify with the existing comparison engine. Atomically append full observations,
events and baseline/version updates. Keep rejected captures/reasons without
advancing the baseline; append accepted unchanged captures without publishing
`no_change` alerts. Repeated submission must verify the stored original request,
not overwrite it. Store provenance sufficient to check retries. No such service
or comparison logic is implemented here.

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

No SQLite schema proof, production migration compatibility, service immutability,
idempotency, concurrency correctness, UI or operational readiness is claimed by
this slice. Those require their own delivery and acceptance evidence. This cohesive
migration/harness/tests/contract slice exceeds the advisory review size so that its
safety prerequisites and public assertions remain together.

### Current isolated delivery checks

Using existing PHP 8.5.6 and PostgreSQL 17.9 runtimes, the delivered candidate
passed the 48-test pure prerequisite suite (130 assertions, exit 0). Ordinary
schema discovery produced seven explicit skips and zero assertions (exit 0).
The separately authorized PostgreSQL run passed seven tests and 59 assertions
(exit 0), with zero public tables before and after. Only newly provisioned
synthetic test state was used. Existing application/diagnostic databases were not
used. This is an exact-code delivery with fresh ordinary verification, not a new
behavior change or a claimed new RED/GREEN cycle.
