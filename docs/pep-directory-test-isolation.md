# PEP pilot: pre-migration isolation guard

**Scope:** isolated pilot test prerequisite only. No storage implementation,
application integration, migrations or permission to execute migrations is supplied.
Independent risk-item2 verification is required before relying on this guard.

## Compose before refresh, never after setup

`InteractsWithTestCaseLifecycle::setUpTheTestEnvironment()` invokes `setUpTraits()`
before Laravel's parent setup returns. `RefreshDatabase::refreshDatabase()` invokes
`beforeRefreshingDatabase()` before in-memory restoration, migration and transaction
setup. A check after `parent::setUp()` is too late.

`Tests\Support\GuardsDisposableDatabase` intentionally defines **no hook**. In a
future, explicitly isolated pilot test class using `RefreshDatabase`, add the trait
and compose its check as the **first operation** in the existing hook:

```php
use GuardsDisposableDatabase;

protected function beforeRefreshingDatabase(): void
{
    $this->assertDisposableDatabase(
        $this->app['config']->get('database'),
        $this->connectionsToTransact(),
        $this->migrateFreshUsing(),
        ['pilot' => ['driver' => 'sqlite', 'database' => ':memory:']],
    );

    // Preserve existing hook safeguards here (including parent/aliased hooks).
    // Do not replace or omit them when composing this check.
}
```

The example is not wired into the shared TestCase. Its pin assumes the independently
prepared default alias is `pilot`; it does not configure that alias. PostgreSQL pins
must contain exactly `driver`, `host`, integer `port`, and `database`; SQLite pins
exactly `driver` and `database`. Pin key order is irrelevant. Supply every intended
alias explicitly. Pins must come from a separately reviewed disposable harness,
**never from the effective config being checked**, a URL, `APP_ENV`, or a `_test`
suffix. The trait cannot establish provenance or disposability of a caller's pins.

The guard reads the effective configuration repository (including cached-config
results), parses connection arrays or URL strings with Laravel's
`ConfigurationUrlParser`, and checks all of:

- The default target, even when transactions or migration use another alias.
- Every actual `connectionsToTransact()` entry; null means the default.
- The actual `migrateFreshUsing()['--database']` override; absent/null means default.

Migration and transaction aliases may differ **only if each independently matches
its own pin**. Missing configuration, empty transaction lists, unknown aliases or
drivers, URL redirection, read/write keys (even null), alternate `connect_via_*`
targets and unknown connector options refuse with one credential-free
`RuntimeException` message. The guard never resolves a database manager, connector,
filesystem path or PDO. Canonical decimal port strings normalize to integer ports.

This is deliberately not a general connection validator: only stock SQLite and
PostgreSQL configurations are supported. SQLite accepts `:memory:` or absolute
`.sqlite` paths with simple alphanumeric/underscore/hyphen path segments, not URIs
or relative paths. PostgreSQL accepts a single simple hostname/IPv4 address, explicit
port and simple database name; no IPv6, socket paths, host lists or DSN fragments.
The small accepted ancillary-key list is in `disposableIdentity()`; extra settings
fail closed rather than guessing their safety.

## Bootstrap is a separate boundary

Pin environment, environment-file selection, configuration-cache path/content and
bootstrap **before application bootstrap**. Do not load SIMO's ordinary `.env` or
cached application configuration just to run these pure tests. A future database
harness must separately prevent bootstrap/provider connections, already-resolved or
dynamic connections, config mutation after checking, custom connector behavior,
queue/HTTP/Gemini side effects and migration/seed code targeting other connections.
The hook only checks the supplied effective stock connection configuration; it
cannot sandbox arbitrary migration code. Avoid combining other database-mutating
traits without independent pre-mutation checks.

Exact identity is not process isolation: DNS/proxies, SQLite symlinks and file
replacement can redirect an apparently identical target. Provision isolated
filesystem/network/credentials and independently verify ownership first. This helper
is neither a substitute for process isolation nor authorization to migrate anything.

## Pure offline verification

Use an existing PHP runtime compatible with the repository dependencies.
The example uses an existing local image; it does not install or pull one.
Run from a checkout with Composer dependencies already available:

```sh
docker run --rm --pull=never --network=none --read-only --mount type=bind,src="$(pwd)",dst=/work,readonly --workdir /work gentle/app:8.5 php vendor/bin/phpunit --no-configuration --bootstrap vendor/autoload.php --do-not-cache-result tests/Unit/PepDirectory/DisposableDatabaseGuardTest.php
```

Use a finite 30-second execution limit. No writable scratch is needed. PHPUnit's
configuration is disabled; only Composer autoload and this pure PHPUnit TestCase
load. Tests exercise Laravel's actual `RefreshDatabase::refreshDatabase()` hook,
replacing all downstream database work with callbacks. Refusals assert the exact
exception/message and an empty callback log (zero migration/connector/PDO calls).
Permitted identities must reach the migration and connector sentinels and the
preserved existing-safeguard sentinel. No stored records exist or are inspected.
The cached-config case supplies an already-effective redirected repository value;
it does not read or build a real cache. No app bootstrap, artisan, actual migrations,
connections, production fixtures, network or dependencies are needed.
