# PG17 native catalog collection — evidence only

`Tests\Support\PepDirectoryInstalledCatalog::collect(Connection): array` reads the fixed
`tests/Fixtures/PepDirectory/schema-contracts/installed-pg17.sql`. It creates no connection,
application, installation, transaction, manifest or approval. There are no SQL/namespace
arguments and no `assertInstalled` API. Unsupported drivers, non-17 servers, non-postgres
users, effective namespaces other than `{public}`, and malformed envelopes fail closed.
The helper performs a context SELECT then one native JSON projection SELECT; neither changes
GUCs/search_path. This is not a concurrency-safe schema attestation or authorization to write.
Existing pure contract pins and `unattested_v2` outcomes are unchanged.

## Returned evidence

Arrays retain native JSON booleans, integers, nulls and arrays (ACL entries are PostgreSQL's
native ACL text). Entries are deterministically ordered by qualified semantic identities;
column ordinals and ordered index/argument vectors remain explicit. Native deparsers retain
casts, parentheses and SQL text. No SQL parser or PHP normalizer is involved. OID references
are resolved to identities, never exposed as object IDs. Ordinals, typmods and trigger bitflags
are semantic numbers, not object OIDs. No rows, sequence last values, filenodes, statistics,
allocation counters, physical dropped-column tombstones or raw pg_node_tree values are read.

Sections describe public relation kinds/owners/options/ACL/RLS/partition metadata,
sequence definitions/ownership, columns, constraints, indexes (including INCLUDE via native
definition plus key counts), triggers (including internal FK activation), overloaded routines,
and policies. Direct public namespace dependencies additionally retain type/kind/identity
through `pg_depend` + `pg_identify_object`. Unknown kinds are visible, not classified as safe.
Aggregate routines retain kind/identity/source/attributes without calling the function-only
definition deparser; this is not a complete aggregate-definition or universal security model.
Foreign tables, extension membership, rewrite rules, domains/types, cross-schema dependency
closure and security labels are not fully described/approved. Relation rule/trigger presence
is explicit. Object registry presence is not a substitute for a closed inventory policy.

## Internal trigger stability

PG17 generates `RI_ConstraintTrigger_[ac]_<trigger oid>`. Only internal FK triggers with a
known pg_catalog RI function and the exact expected prefix AND their own allocated suffix
are represented without that generated name/native definition. Their identity combines table,
constraint, native flags and qualified function; activation, arguments, constraint/index,
deferral and transition metadata remains. The function disambiguates self-referencing FK
check/action triggers with identical flags. Custom/anomalous names and custom native trigger
definitions are retained verbatim, never rewritten. Unknown generated naming schemes remain
visible, potentially unstable, rather than being silently normalized. Partition-trigger parent
naming and anomalous-name recreation have not been exercised by these fixtures.

## Current bounded verification

`InstalledCatalogCollectionTest` uses the inherited populated-target guard, disables only the
authorization overlay, and starts with plain V1 inside the harness's outer transaction.
The unchanged V1 migration SHA256 is
`63975cd489be968dd6547d17c2170e7db73bb80858a41980b499602f5aa4b548`.
The new test pins the inherited V2 migration SHA256
`b44245597fd7a9c6742c65e7bacaa8f9f8d21c0a4a41e75fa396e9b9572d7215`.
Source comparison established that only the seven-line SQLite test-discovery guard differs
from the historical migration; the migration itself was not edited. A DB-free PHP `hash_file`
check failed against the historical expected hash (exit1), then passed against the inherited
hash (exit0). This checks the anchor correction, not new collector behavior.

Both source anchors are checked before collection. The routine body is sliced from the pinned
source before temporary V2 creation/observation. Literal V1 facts cover tables, columns/default/type,
FK/index/native flags, sequences and function/custom-trigger absence. Temporary V2 covers ten tables,
the reviewed routine body/full definition/settings, trigger definition/flags, default and predicate.
Only inside nested transactions does the test add the Fuente fields and call migration `up()`.
Two savepoint creations compare exact metadata after rollback/recreation; no migration `down()`,
manual DROP, commit or row allocation is used. Outer rollback reattests preservation and
function/custom-trigger absence. Unsupported contexts/envelopes have mock negative coverage.

One positive invocation passed **3 tests / 222 assertions**, exit0, empty stderr, with
PHP 8.5.6 / PHPUnit 11.5.55. Once each, absent opt-in produced 3 skips/exit0; empty and invalid
opt-in produced 3 setup errors/exit2 each, before application/connection setup. The checks used
an existing pinned PHP image and isolated synthetic PostgreSQL17 target, not a fresh image build.
Full read-only snapshots before/after, decoded with Python exact integers, matched the prior
independent raw snapshot: six tables / 38 retained rows, all columns, constraints, indexes,
relations, routines, triggers, extensions and schemas unchanged. Users and fuentes retained
only `[id, label]`; no authorization or qualification overlay remained. The initial receipt
checker incorrectly expected no triggers, overlooking the 28 inherited internal FK triggers;
offline comparison accepted the already captured equal snapshots without repeating SQL.

Native collection is **not installed-schema admission or operational qualification**.
No Python implementation/parity, additional ACL/extension fixture coverage, arbitrary-schema
stability, permanent V2 installation, concurrency, production-cache behavior, restart durability,
sequence-value preservation, rollout or production migration is established here. SQLite was
not exercised. Exact collector/SQL delivery has no meaningful new behavior-level RED.

## Python cursor adapter — offline protocol proof only

`scripts/website_monitor_pro/tests/pep_directory_installed_catalog.py` provides
`collect(connection)` using the inherited fixed SQL projection. It imports no DB driver
and creates no connection, transaction, installation or approval. The caller must first
establish its authorized target. The adapter expects a tuple-row cursor context manager
and native integer context version, then a decoded JSON object or JSON string. It preserves
native values and never commits, rolls back or closes the caller's connection; the cursor
context exits on success and rejection. Cursor closure relies on that context-manager protocol.

The accompanying `test_pep_directory_installed_catalog.py` passed **6 offline test methods**,
exit0, in one focused invocation using an existing Python environment directly with `-B`.
Stdout was empty; stderr contained the verbose unittest results ending in `OK`.
Mock cursors exercise fixed SQL, typed values, context/envelope/result rejection, missing SQL
and extra-argument rejection. These are protocol checks only, not actual PostgreSQL driver
execution, PHP/Python parity, application-runtime proof or permission to connect.
The exact historical Python port has no meaningful new behavior-level RED; this is ordinary
functional verification, not a new RED/GREEN cycle.

The PHP collector, shared SQL, existing tests, migration anchors and all other tracked files
outside this delivery's documentation remain unchanged. No database was accessed for this
Python verification, so it establishes no fresh database-preservation claim. No runtime image,
monitor, browser or capture was used. Actual driver parity, additional ACL/extension fixtures,
installed-schema admission and all previously stated limits remain pending.
