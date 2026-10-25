<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** No project bootstrap, dotenv, config files, providers, kernel or global migrations. */
abstract class PepDirectoryDatabaseTestCase extends TestCase
{
    use GuardsDisposableDatabase;
    use RefreshDatabase;

    public const TARGET = 'pep_schema_20261018_d83b91';
    public const MIGRATION = 'database/migrations/2026_10_18_000001_create_pep_directory_shadow_tables.php';

    protected function setUp(): void
    {
        $engine = getenv('PEP_SCHEMA_ENGINE');
        if ($engine === false) {
            $this->markTestSkipped('PEP directory schema checks require explicit PEP_SCHEMA_ENGINE=pgsql opt-in.');
        }
        if ($engine !== 'pgsql') {
            throw new RuntimeException('S13 authorizes PostgreSQL schema checks only.');
        }
        parent::setUp();
    }

    public function createApplication(): Application
    {
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            putenv($key.'=/tmp/pep-schema/'.$key.'.php');
            $_ENV[$key] = $_SERVER[$key] = '/tmp/pep-schema/'.$key.'.php';
        }
        $app = new Application('/tmp/pep-schema');
        $app->useEnvironmentPath('/tmp/pep-schema')->loadEnvironmentFrom('never-load.env');
        $app->useStoragePath('/tmp/pep-schema/storage');
        $connection = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => self::TARGET,
            'username' => 'postgres', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'disable'];
        $app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'key' => '', 'debug' => false],
            'database' => ['default' => 'pilot', 'connections' => ['pilot' => $connection]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'gemini' => ['enabled' => false, 'api_key' => null],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
        ]));
        $app->register(DatabaseServiceProvider::class);
        $app->register(\Illuminate\Queue\QueueServiceProvider::class);
        $app->boot();
        Facade::setFacadeApplication($app);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();

        return $app;
    }

    protected function connectionsToTransact(): array
    {
        return ['pilot'];
    }

    protected function migrateFreshUsing(): array
    {
        return ['--database' => 'pilot'];
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertDisposableDatabase(
            $this->app['config']->get('database'), $this->connectionsToTransact(), $this->migrateFreshUsing(),
            ['pilot' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => self::TARGET]],
        );
        // Only now may a stock connector run; verify selected DB before any mutation.
        $db = $this->app['db']->connection('pilot');
        if ($db->selectOne('SELECT current_database() AS name')->name !== self::TARGET) {
            throw new RuntimeException('Selected schema target mismatch.');
        }
        $db->statement("SET statement_timeout = '5s'");
        $db->statement("SET lock_timeout = '5s'");
    }

    protected function refreshTestDatabase(): void
    {
        // PostgreSQL transactional DDL leaves this owned target empty after every test.
        // Never migrate:fresh, empty an existing schema, or scan the legacy ledger.
        if ($this->app['db']->connection()->getSchemaBuilder()->getTableListing() !== []) {
            throw new RuntimeException('Owned empty schema-test target required.');
        }
        $this->beginDatabaseTransaction();
        foreach (['tests/Fixtures/PepDirectory/migrations/0000_01_01_000001_create_shadow_test_parents.php', self::MIGRATION] as $path) {
            if (is_file('/work/'.$path)) {
                (require '/work/'.$path)->up();
            }
        }
    }
}
