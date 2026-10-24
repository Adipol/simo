<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** Populated-target pilot only: no RefreshDatabase, project bootstrap or migration scan. */
abstract class PepDirectoryReviewTestCase extends TestCase
{
    use GuardsDisposableDatabase;

    public const TARGET = 'pep_schema_20261018_d83b91';
    public const TABLES = ['fuentes', 'pep_directory_events', 'pep_directory_observations', 'pep_directory_reviews', 'pep_directory_sources', 'users'];

    protected Connection $db;
    protected \Illuminate\Database\Migrations\Migration $authorization;
    private array $originalRows = [];
    private array $originalColumns = [];
    protected bool $committedAuthorization = false;

    protected function setUp(): void
    {
        self::requireOptIn(); // BEFORE parent setup, Application, pins, connector or PDO.
        parent::setUp();
        $this->db = $this->app['db']->connection();
        $tables = $this->db->getSchemaBuilder()->getTableListing('public', false);
        sort($tables);
        if ($tables !== self::TABLES) {
            throw new RuntimeException('Expected exactly the six retained synthetic tables; no changes made.');
        }
        foreach (self::TABLES as $table) {
            $this->originalColumns[$table] = $this->db->getSchemaBuilder()->getColumnListing($table);
            $this->originalRows[$table] = $this->db->table($table)->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        }
        echo 'BEFORE '.json_encode(['counts' => array_map('count', $this->originalRows),
            'users_columns' => $this->originalColumns['users'], 'fingerprint' => hash('sha256', json_encode($this->originalRows, JSON_THROW_ON_ERROR))], JSON_THROW_ON_ERROR).PHP_EOL;
        $this->db->beginTransaction();
        if ($this->usesAuthorizationOverlay()) {
            $this->authorization = require '/work/tests/Fixtures/PepDirectory/migrations/2026_10_18_000002_review_test_authorization.php';
            $this->authorization->up();
        }
    }

    /** Internal fixture choice; target, inventory and rollback guards always apply. */
    protected function usesAuthorizationOverlay(): bool
    {
        return true;
    }

    public static function requireOptIn(): void
    {
        $engine = getenv('PEP_REVIEW_ENGINE');
        if ($engine === false) {
            throw new \PHPUnit\Framework\SkippedWithMessageException('PEP review checks require explicit PEP_REVIEW_ENGINE=pgsql opt-in before setup.');
        }
        if ($engine !== 'pgsql') {
            throw new RuntimeException('S13 authorizes PostgreSQL review checks only.');
        }
    }

    public function createApplication(): Application
    {
        self::requireOptIn();
        foreach (['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'] as $key) {
            putenv($key.'=/tmp/pep-review/'.$key.'.php');
            $_ENV[$key] = $_SERVER[$key] = '/tmp/pep-review/'.$key.'.php';
        }
        $app = new Application('/tmp/pep-review');
        $app->useEnvironmentPath('/tmp/pep-review')->loadEnvironmentFrom('never-load.env');
        $app->useStoragePath('/tmp/pep-review/storage');
        $connection = ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => self::TARGET,
            'username' => 'postgres', 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'sslmode' => 'disable'];
        $app->instance('config', new Repository([
            'app' => ['env' => 'testing', 'key' => '', 'debug' => false, 'locale' => 'en', 'fallback_locale' => 'en'],
            'database' => ['default' => 'pilot', 'connections' => ['pilot' => $connection]],
            'auth' => ['defaults' => ['guard' => 'web'], 'guards' => ['web' => ['driver' => 'session', 'provider' => 'users']],
                'providers' => ['users' => ['driver' => 'eloquent', 'model' => \App\Models\User::class]]],
            'permission' => require '/work/config/permission.php',
            'cache' => ['default' => 'array', 'stores' => ['array' => ['driver' => 'array', 'serialize' => false]]],
            'queue' => ['default' => 'sync', 'connections' => ['sync' => ['driver' => 'sync']]],
            'gemini' => ['enabled' => false, 'api_key' => null],
            'logging' => ['default' => 'null', 'channels' => ['null' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class]]],
        ]));
        // Configuration-only guard BEFORE registering a database provider or resolving a connector.
        $this->assertDisposableDatabase($app['config']->get('database'), ['pilot'], ['--database' => 'pilot'],
            ['pilot' => ['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 5432, 'database' => self::TARGET]]);
        foreach ([\Illuminate\Filesystem\FilesystemServiceProvider::class, \Illuminate\Database\DatabaseServiceProvider::class, \Illuminate\Cache\CacheServiceProvider::class,
            \Illuminate\Translation\TranslationServiceProvider::class, \Illuminate\Validation\ValidationServiceProvider::class,
            \Illuminate\Queue\QueueServiceProvider::class, \Spatie\Permission\PermissionServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->boot();
        Facade::setFacadeApplication($app);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        $db = $app['db']->connection();
        $identity = $db->selectOne('SELECT current_database() AS name, current_user AS actor, current_schemas(false)::text AS schemas');
        if ($identity->name !== self::TARGET || $identity->actor !== 'postgres' || $identity->schemas !== '{public}') {
            throw new RuntimeException('Selected review target/user mismatch.');
        }
        $db->statement("SET statement_timeout = '5s'");
        $db->statement("SET lock_timeout = '5s'");

        return $app;
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db)) {
                while ($this->db->transactionLevel() > 0) {
                    $this->db->rollBack();
                }
                if ($this->committedAuthorization) {
                    // Only structures whose absence was established by up(); no parent/history cleanup.
                    $this->db->transaction(fn () => $this->authorization->down());
                }
                Queue::assertNothingPushed();
                Http::assertNothingSent();
                $preservedRows = [];
                foreach ($this->originalRows as $table => $rows) {
                    $ids = array_column($rows, 'id');
                    $actual = $this->db->table($table)->select($this->originalColumns[$table])->whereIn('id', $ids)
                        ->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
                    $preservedRows[$table] = $actual;
                    self::assertSame($rows, $actual, 'Pre-existing row fingerprint changed: '.$table);
                    self::assertSame($this->originalColumns[$table], $this->db->getSchemaBuilder()->getColumnListing($table));
                }
                $tables = $this->db->getSchemaBuilder()->getTableListing('public', false);
                sort($tables);
                self::assertSame(self::TABLES, $tables, 'Introduced authorization structures not restored.');
                echo PHP_EOL.'PRESERVED '.json_encode([
                    'before' => hash('sha256', json_encode($this->originalRows, JSON_THROW_ON_ERROR)),
                    'after' => hash('sha256', json_encode($preservedRows, JSON_THROW_ON_ERROR)),
                    'counts' => array_combine(self::TABLES, array_map(fn (string $table): int => $this->db->table($table)->count(), self::TABLES)),
                    'inventory' => $tables,
                ], JSON_THROW_ON_ERROR).PHP_EOL;
            }
        } finally {
            parent::tearDown();
        }
    }
}
