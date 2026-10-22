# Dormant directory review domain

This slice adds read models, a validated decision DTO and an authorized review
service over the shadow tables. It adds no routes, UI, observer, monitor wiring,
production permissions or activation. Confirmation reviews evidence; it does not
establish legal tenure, appointment/departure dates or an effective date.

## Decision contract

Call `ReviewService::decide(eventId, actor, decision)` with a server-authenticated
actor, never a request-selected user ID. Build the DTO with
`ReviewDecisionData::fromArray()` using exactly `state`, `expected_version` and
`reason`. States are `pending`, `confirmed`, `discarded`. Version must be a native
integer from zero to below PHP_INT_MAX. The reason is preserved verbatim, must
contain non-whitespace text, valid UTF-8, no NUL and at most 10,000 characters.
Unknown fields are rejected.

The service locks the event, reloads and locks the persisted actor on the event's
connection, clears permission cache and checks current `activo` and actual
`gestionar fuentes` permission. Caller attributes, cached relations and role names
are not authority. Authorization precedes version checking. Every accepted
decision, including a same-state decision, appends the next audit version and
atomically updates only the event review projection. Actor identity/snapshot comes
from persisted server state; PostgreSQL supplies the decision timestamp.

`ReviewConflictException` reports expected and actual versions. There is no
automatic retry. For example, two decisions at version zero produce one version-one
winner; the other caller must reload and explicitly decide again at version one.
Evidence payloads, observations, baselines and earlier review entries are retained.

## Read model boundary

`PepDirectorySource`, `PepDirectoryObservation`, `PepDirectoryEvent` and
`PepDirectoryReview` expose relationships and typed casts. Instance save/delete
refusals are application safeguards, not database triggers or a prohibition on
bulk/query-builder writes. Only the service's authorized transaction owns review
projection and audit mutation. No producer is connected by this delivery.

Permission-cache invalidation is nontransactional. Array-cache tests do not prove
production shared-cache behavior; deployment/integration must assess that boundary
separately. No production or operational approval is implied here.

## Isolated verification

Use only an explicitly authorized, independently owned PostgreSQL test container.
Verify its exact ID, owner, image, running state, network isolation, lack of exposed
ports and host data volumes before access. The hardcoded target
`pep_schema_20261018_d83b91` at `127.0.0.1:5432` does not itself establish ownership.
Retain the six-table synthetic schema prepared for storage verification. Do not
rerun its installer, clear data or run global migrations.

Mount the candidate read-only at `/work`, with existing compatible dependencies
mounted read-only at `/work/vendor` and a private writable `/tmp` tmpfs. No Composer
installation, application bootstrap or dotenv loading is needed. Pure opt-out
checks use no network; positive tests share only the verified test container's
network namespace.

Inside that isolated PHP runtime:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/PepDirectory/ReviewServiceTest.php
PEP_REVIEW_ENGINE=pgsql php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/PepDirectory/ReviewServiceTest.php
```

Absent opt-in skips before Laravel setup; empty or unsupported values fail before
setup and are expected refusal checks, not passing behavior tests. Positive setup
verifies database/user/search path and exact six-table inventory, snapshots existing
rows/columns, then adds only test authorization metadata transactionally. SQL and
lock timeouts are bounded.

Run parent and independent verification serially. Ordinary cases roll back;
concurrency checks commit fresh synthetic fixtures to allow independent processes.
They observe actual PostgreSQL blocking, require one winner/one version conflict,
and test an explicit retry. Teardown removes only introduced authorization metadata
and verifies original rows/columns plus restored six-table inventory. Committed
concurrency evidence remains. Interrupted cleanup is a stop-and-inspect condition,
not permission to wipe or repair the target automatically.

The concurrency helper is invoked by the suite, not manually. No private panel,
original/diagnostic database, capture or external service belongs in these checks.
This exact historical-code port requires fresh verification; historical counts or
RED/GREEN chronology are not current proof. The cohesive service/models/harness
slice exceeds advisory review size to keep authorization and concurrency evidence
with the behavior it covers.

## Current isolated checks

Parent verification with the existing PHP8.5.6 runtime passed nine PostgreSQL tests
and 402 assertions (exit 0), including observed database blocking and preserved
preexisting rows. Test-owned authorization metadata was removed; six-table inventory
was restored and new concurrency evidence retained. No opt-in produced nine skips
(exit 0); empty and invalid opt-ins each produced nine setup refusals (exit 2), as
expected. The historical S13 wording in refusal messages is not authorization.
These are current candidate checks, not production shared-cache or UI proof.
