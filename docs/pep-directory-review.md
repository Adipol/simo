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

## Isolated Livewire/HTTP prerequisite

The test-only Livewire harness extends the retained review harness. Its HTTP
kernel has zero bootstrappers: it never loads project bootstrap, providers,
routes or dotenv. Explicit Auth, Hash, Cookie, Session, Encryption, View and
Livewire providers use a synthetic key, array session/cache, read-only existing
Blade views and temporary compiled views. Normal environment/config/provider
bootstrap events fail closed. Included-file/provider assertions check isolation;
this is not an operating-system file-access trace.

Four probes establish only this prerequisite:

- Real `Livewire::test()` rendering and signed snapshot, updates, action execution,
  required/min validation errors and successful rendered state. For example, `AB`
  fails the minimum length; `Synthetic Value` saves and appears in the output.
- Disabled mounting returns 404 before its model query. A `beforeExecuting`
  sentinel observes zero directory queries, not merely zero completed queries.
- Separate HTTP kernel requests enable the real auth, active-user and inspection
  permission aliases: guest 401, missing permission 403, allowed active session
  200, then persisted deactivation redirects and logs out. SessionGuard reloads
  the actor rather than trusting an already-loaded active User object.
- The complete existing layout renders through HTTP with synthetic named routes,
  actor and slot. Vite is stubbed; no build, browser or client JavaScript runs.

`Livewire::test()` bypasses middleware, so only the separate HTTP requests supply
middleware evidence. The minimal web group contains session/errors middleware,
not the complete production middleware or CSRF stack. These probes do not prove
future directory UI behavior, component authorization, feature gating, formal
review mutations or operational readiness. No production routes or UI change.

### Current bounded verification

The three PHP files are byte-exact historical exports. No meaningful behavioral
RED applies to this exact port; the results below are fresh functional checks,
not a newly invented test-first lifecycle.

Verification used the existing immutable PHP image, read-only candidate/vendor
mounts and a private read-only execution adapter. The adapter changes exactly one
inherited `public const TARGET` literal to the authorized synthetic test database;
the tracked parent harness remains unchanged. Fresh resource checks confirmed the
owned persistent PostgreSQL target, expected image and sole named data volume,
network none, no exposed ports and a read-only container root. No installer,
reset, global migration or application bootstrap ran.

The focused command inside that runtime was:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/PepDirectory/LivewireHarnessTest.php
```

Serial checks, each bounded to 30 seconds, observed:

- Absent `PEP_REVIEW_ENGINE`: exit 0, four visible skips, zero assertions, network
  none; no positive behavior is inferred from this run.
- Empty and invalid opt-ins: each exit 2, four setup errors, zero assertions,
  network none. These are expected refusals before Application/connection setup.
- One `PEP_REVIEW_ENGINE=pgsql` run: exit 0, **4 tests / 229 assertions** on PHP
  8.5.6 and PHPUnit 11.5.55. PHPUnit reported **2 deprecations**, unsuppressed;
  the prescribed command did not print their detailed messages. Stderr was empty.
  No diagnostic rerun or vendor modification was performed.

Read-only exact-row and schema snapshots before/after matched. All **38 retained
rows** remained unchanged: 6 fuentes, 6 sources, 22 observations, 4 events, zero
users and reviews. Exactly six tables remained, users again had only `id,label`,
and no authorization metadata remained. Test DDL, users and permissions rolled
back in the parent transaction; no committed fixtures were added. Sequence gaps
are permitted and sequence values were not reset. Source/adapter hashes and the
inherited documentation prefix were checked without modifying inherited files.

This is writer verification only; independent verification remains a separate
review gate. No original/diagnostic database, external HTTP, queue, scraper,
Gemini, live capture, browser or deployment was accessed.

## Default-off directory review UI

This delivery adds `/pep/directorio` as a sibling of Cambios PEP, not a
replacement. `config/pep_directory.php` sets `ui_enabled` to literal **false**.
The route inherits auth/active-user checks and requires `ver cambios pep`; the
navigation link additionally requires the flag. Disabled initial and hydrated
component requests return 404 before directory SQL. Enabled component boot reloads
persisted active/read authorization. Formal decisions require `gestionar fuentes`
and go through the existing ReviewService with the server-authenticated actor.
Legacy acknowledgement permission does not grant formal review authority.

The Spanish page separates before/after and outgoing/incoming groups, escapes
original evidence, and distinguishes observation/detection from unknown effective
dates. Confirmation is human review, not legal tenure. Source history includes
accepted unchanged and rejected observations; displayed holders match exact unit
and role, without normalization. Nullable source links and optional legacy fields
remain supported. Existing DST, unknown-holder and orphan-cell behavior is untouched.

Selection/version fields are Locked. Reasons use the existing DTO validation.
Conflicts preserve the displayed version and block automatic retries; for example,
a stale version-zero decision must explicitly reload version one and decide again.
Successful decisions also require explicit reload before another review. Historical
actor snapshots remain visible alongside any live-user supplement. URL filters are
validated and reset proposal pagination; history pagination is independent.

### Fresh bounded UI checks

The component, Blade view and public test are byte-exact historical exports; the
only shared route/layout edits are the sibling route and gated link. No later
configuration or harness overlay was imported. The inherited harness is unchanged;
a private execution copy changes exactly one target literal, checked by source hash
and reverse identity. No meaningful new RED applies to this exact-code port.

The baseline route, auth route and layout were freshly exported from the immutable
predecessor commit and mounted read-only at `/baseline`. Public tests use only
`PEP_UI_BASE_DIR`; they do not read Git. With the flag off, tests compare route
names/methods/URIs/middleware except the intentional sibling, rendered public links
and full layout markup around the unchanged legacy Blade slot. Normalization is
limited to whitespace and inert Livewire morph markers. Enabled navigation adds
only the permitted sibling link. Vite is stubbed and legacy models are unsaved
synthetic fixtures, not legacy database reads.

The focused command inside the isolated existing PHP runtime was:

```sh
php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Feature/PepDirectory/DirectoryReviewUiTest.php
```

Fresh serial checks, each bounded to 30 seconds, observed:

- Absent opt-in, network none: exit **0**, **10 skips / 0 assertions**.
- Empty and invalid opt-ins, network none: each exit **2**, **10 setup errors /
  0 assertions**, the expected fail-closed refusal before Application/connection.
- Exactly one positive PostgreSQL run: exit **0**, **10 tests / 365 assertions**,
  PHP 8.5.6 / PHPUnit 11.5.55, 1.075 seconds, **2 unsuppressed deprecations**.
  The prescribed command did not print detailed deprecation messages. Separate
  stdout, stderr and exit receipts were retained; stderr was empty for all runs.

Livewire initial/hydrated requests and real service actions cover disabled gating,
read/write permission revocation, persisted deactivation, all three decisions,
actor attribution, reason validation, stale conflict/explicit reload, locked-field
tampering, escaped evidence, exact-holder history, nullable payloads, filters and
independent paginators. Separate HTTP requests exercise the actual production
route with auth/active/read-permission aliases, default-off 404 and enabled 200.
This minimal kernel is not the full production middleware or CSRF stack.

Fresh identity checks verified the owned persistent PostgreSQL resource before
access and immediately before the positive run. Candidate/vendor/root were
read-only, with temporary compiled views in tmpfs and no external network. Exact
read-only before/after row and schema snapshots matched: **38 retained rows**,
6 fuentes, 6 sources, 22 observations, 4 events, zero users/reviews. Exactly six
tables remained; users had only `id,label` and no auth metadata remained. All test
authorization DDL and fixtures rolled back; no committed concurrency, reset,
reinstall, sequence reset or other database access occurred. Sequence values were
not compared; gaps are permitted.

Every enabled read boot invalidates Spatie permission cache, in addition to the
service's mutation-time invalidation. Neither side effect rolls back with SQL;
permission invalidation was tested with array cache only, not a production shared
cache. Browser/JavaScript, full middleware/CSRF, production caches, operational
binding and activation remain unproven. The ordinary full suite is deferred to CI,
not run against the retained target. This is writer evidence, not independent
review or deployment approval.
