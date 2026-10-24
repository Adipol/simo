# Owned PEP fixture schema contract

**Only V1 can match successfully; V2 is prospective design, not installed-schema acceptance.**
This additive fixture-validation prerequisite does not authorize database changes.
Neither validator connects, introspects, executes SQL, mutates input, or writes files.
No existing production call path is connected to these validators.

## API

- PHP: `Tests\Support\PepDirectorySchemaContract::validate($snapshot)`.
- Python: `scripts/website_monitor_pro/tests/pep_directory_schema_contract.py`,
  `validate(snapshot)` (stdlib only).
- Both read only the fixed, repository-owned
  `tests/Fixtures/PepDirectory/schema-contracts/contract.json`.
  There is no caller-selected version, manifest, or executable manifest.

| Return | Meaning |
|---|---|
| `v1` | Exact six-table normalized fixture inventory matched |
| `unattested_v2` | Exact prospective structure matched; **not eligible** for installed-schema use |
| `schema_mismatch` | Unknown, hybrid, incomplete, extra, or altered metadata |
| `contract_unavailable` | Fixed contract is unreadable, malformed, incomplete, or not the pinned approved file |

Only `v1` is success. For example, compare the result to `v1`; never treat a
nonempty return string as truthy success. Python rejects extra API arguments with
`TypeError`; PHP explicitly refuses extra positional arguments with
`schema_mismatch` (PHP otherwise silently ignores them).

Before matching input, both validators check required version-section structure
and the approved file's SHA-256 identity. Decoding JSON alone is not validation:
null, missing, scalar, empty or truncated expected sections cannot validate equally
malformed input. The identity pin covers nested completeness without duplicating
the inventory. Any contract edit (including formatting) requires review and updating
both private pins. The approved fixture SHA-256 is
`281e3f3d99b9037929907fc4590ff69e94fa1b92d06ce7ea00c6379bf8071037`.
This pin does **not** attest an installed database or supply the missing V2
trigger/function body identities.

Regression tests inject fixed-file reads using Python stdlib mocks and copied PHP
validators in container-only tmpfs; the checkout manifest remains unchanged.

## Snapshot representation

Read `v1` or `v2_design` directly for an inspectable synthetic metadata fixture.
Each is a complete, independent inventory, not a subset or an overlay.
Snapshots are JSON values: associative PHP arrays / Python dictionaries, with
lists preserved. Map order is irrelevant; list order and scalar types are exact.
PHP represents empty JSON maps as empty associative arrays (`[]`).

- Root `schema: public` and `owner: postgres` apply to **every** inventoried
  relation, index, default-owned sequence, trigger and function. A future collector
  must verify this homogeneous ownership, not substitute the current connection user.
- Column entries are `[normalized SQL type, nullable boolean, normalized default
  expression or null]`. Serial defaults explicitly name their owned sequence.
- Constraint/index names are keys. Values are canonical definitions, not executable
  migration SQL. Unspecified modifiers mean PostgreSQL defaults: immediate,
  nondeferrable, validated constraints; valid, ready, non-expression indexes.
  Any nondefault modifier must remain visible and therefore fail matching.
- Canonical definitions omit schema qualification within `public`, identifier
  quoting and redundant expression parentheses; preserve column order, predicates,
  checks, actions, casts and all semantic modifiers. Type aliases use the spelling
  in the contract. No normalizer/introspector is implemented in this slice.
- Trigger/function maps are explicitly empty in V1. V2 requires the declared
  event, scope, lock order, owner and function properties, but both definition/body
  identities are null: there is deliberately no body wildcard or installed V2 success.

## Provenance and prospective design

V1 is derived from `2026_10_18_000001_create_pep_directory_shadow_tables.php`
and `0000_01_01_000001_create_shadow_test_parents.php`, consistent with the review,
storage and shadow integration six-table fixtures. `fuentes` and `users` contain
only `id` and `label`. This task did not observe or attest a running database.

V2 preserves every V1 column, constraint and index, then declares ten tables.
Its fixture-only `fuentes.url` (text), `tipo` (varchar(32)), and `activo` (boolean,
true by default) are explicit additions needed by the proposed trigger. These
are **not** assertions about production Fuente columns or an authorization overlay.

Configurations hold immutable declaration, Python-canonical bytes/revision,
binding/directory URLs and server creator snapshot. Captures hold immutable raw
bytes/hash, metadata, provenance and time. Previews hold exact result bytes/hash,
wire payload, qualification and eligibility version. Decisions hold source-local
sequence, action, configuration/preview and prior/new approval references, actor
kind/identity/snapshot, reason and time. Immutability, authorization, byte/hash
consistency and lifecycle semantics require future implementation; metadata
matching alone does not enforce them. Internal FKs restrict deletion and use
same-source composite keys. The existing Fuente FK still nulls on deletion.
A partial unique index permits one configurable registration per live Fuente.

## Next steps: guards before DDL

1. Independently review these explicit inventories against the approved owned fixture.
2. Implement the additive migration and narrow registered-source-only invalidation
   trigger. Lock live Fuente before shadow source. Invalidate on URL/type changes,
   active true-to-false, and hard deletion; never affect unregistered legacy sources.
3. Attest exact trigger/function definitions after migration implementation; replace
   null identities under a reviewed contract change before allowing V2 success.
4. Only then adapt existing guards, prove their fail-closed behavior **before DDL**,
   and separately authorize rollout on the owned target. No blind migration replay.
5. Add approval/repository gating and permission enforcement separately.

Existing guards remain unchanged. No production/auth-overlay support, PostgreSQL
installation proof, lifecycle/permission proof, live source binding, activation,
network access, database access, or DDL is provided by these pure tests.

## Verification scope

The focused suites cover matching outcomes in PHP and Python: `v1`,
`unattested_v2`, `schema_mismatch`, and `contract_unavailable`. They cover strict
boolean/integer distinction, refused overrides, map-order independence, input
immutability, missing/extra/altered metadata, and malformed fixed-contract structure.
Fault injection uses valid JSON with malformed structure: unreadable files and
syntactically invalid JSON branches are not dynamically covered by these tests.
No full-monitor or HTTP differential, database introspection, V2 installation,
or activation is claimed. Exact historical code delivery has no meaningful new
pre-implementation RED; focused verification is ordinary execution evidence.

Fresh focused results for this candidate:

- Python: 4 test methods passed, no failures/errors/skips (0.025 seconds).
- PHP 8.5.6 / PHPUnit 11.5.55: 4 test methods, 224 assertions passed
  (0.368 seconds), empty stderr.
- Each suite ran once with a 30-second outer bound and exited 0. PHP ran in the
  existing immutable image with no network, read-only checkout/vendor, and only
  bounded ephemeral tmpfs for temporary data and copied-fixture fault injection.
- All five code/test/fixture files remained byte-identical to their historical
  exports after execution; the fixture and both validator pins were unchanged.
- All predecessor tracked files remained unchanged. No database or persistent
  test target was accessed; these results do not provide database lifecycle proof.

## CI portability correction

The later CI run `40044223557` failed in both PHP jobs at the first malformed
contract case (`null_sections`): the expected `[0, 'contract_unavailable', '']`
was replaced by child exit 255 and a missing copied-validator error under the
helper's hardcoded temporary fixture location. Python CI passed. The exact
parent filesystem errno in CI was not established; the missing copied file is
observed evidence, not proof of a specific CI permission error.

Only `SchemaContractTest::probeContract` changed: it now creates a unique directory
under `sys_get_temp_dir()`, preserves the validator's relative `Support` / `Fixtures`
layout, checks every directory/copy/write operation, and closes process resources
and removes only invocation-owned files/directories in `finally`. No compatibility
copy is made at the old location. Validators, fixture pins, test expectations,
Python and workflows are unchanged.

Test-first correction evidence (PHP 8.5.6 / PHPUnit 11.5.55):

- Before RED, the nearest existing ancestor of the hardcoded fixture area was
  `/`, reported non-writable inside the read-only runtime; temporary storage was
  `/tmp`. The original helper was still unchanged.
- RED: the focused malformed-contract command ran once, failing at `null_sections`
  with the same child exit 255 / missing-validator signature: 1 test, 2 assertions,
  1 failure, 4 warnings; PHPUnit/container exit 1 (0.036 seconds).
- GREEN: the identical focused command ran once after the helper fix: 1 test,
  254 assertions, exit 0 (0.372 seconds).
- Triangulation: the whole file ran once with only the filter removed: 4 tests,
  424 assertions, exit 0 (0.411 seconds). Both passing runs had empty stderr.
- Each run used the existing immutable image
  `sha256:71cf917d466a4fe99a1c21c47555654c651488233148ffd0bc4ab9587af2779e`,
  read-only checkout/vendor, no network, no capabilities, no-new-privileges,
  128 PIDs, 512 MiB memory, and only a 32 MiB mode-1777 `/tmp` tmpfs. Each had a
  30-second outer bound; there was no writable mount at the hardcoded location.
- In the same ephemeral runtime immediately after each passing command, a bounded
  wrapper verified the fixture and both validator hashes unchanged and zero
  residual invocation-created temporary directories, preserving the test exit.
  Exact commands, separate stdout/stderr/exit receipts and the wrapper are retained
  as ignored local evidence under `storage/app/pep-directory-delivery/t12-portability-fix/`.

These are local portability results, not a corrected CI result. Cleanup on normal
completion is observed; individual setup/process failure paths were not separately
fault-injected. No database, installation, download, Composer, Artisan, persistent
test environment, deployment or operational activation was involved. Publication
and independent assessment remain separate parent-owned steps.
