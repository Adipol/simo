<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use PHPUnit\Framework\TestCase;

/** Public migration entry points only; all database/schema access is replaced. */
final class ConfigurationMigrationCompatibilityTest extends TestCase
{
    private const REJECTION = 'Directory qualification requires PostgreSQL; SQLite approval is unsupported.';

    /** @return array{outcome: string, message: ?string, schema_calls: int, sql_calls: int, marker: bool} */
    public static function probe(string $migration, string $driver, string $environment, bool $marker, string $method = 'up'): array
    {
        $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
        // Autoload alone must not impersonate the PHPUnit executable. A fresh
        // process lets the negative marker case run even when this test uses PHPUnit.
        $code = 'require '.var_export($autoload, true).';'
            .($marker ? 'define("PHPUNIT_COMPOSER_INSTALL", '.var_export($autoload, true).');' : '')
            .'$driver = '.var_export($driver, true).';'
            .'$environment = '.var_export($environment, true).';'
            .'$migration = '.var_export($migration, true).';'
            .'$method = '.var_export($method, true).';'
            . <<<'PHP'
$app = new Illuminate\Foundation\Application(sys_get_temp_dir().'/pep-migration-probe');
$app->instance('env', $environment);
Illuminate\Support\Facades\Facade::setFacadeApplication($app);
$db = new class($driver) {
    public int $sqlCalls = 0;
    public function __construct(private string $driver) {}
    public function connection(): self { return $this; }
    public function getDriverName(): string { return $this->driver; }
    public function __call(string $name, array $arguments): never
    {
        $this->sqlCalls++;
        throw new RuntimeException('unexpected database call: '.$name);
    }
};
$schema = new class {
    public int $calls = 0;
    public function __call(string $name, array $arguments): never
    {
        $this->calls++;
        throw new RuntimeException('schema sentinel: '.$name);
    }
};
Illuminate\Support\Facades\DB::swap($db);
Illuminate\Support\Facades\Schema::swap($schema);
$outcome = 'returned';
$message = null;
try {
    (require $migration)->{$method}();
} catch (Throwable $exception) {
    $outcome = get_class($exception);
    $message = $exception->getMessage();
}
echo json_encode(['outcome' => $outcome, 'message' => $message,
    'schema_calls' => $schema->calls, 'sql_calls' => $db->sqlCalls,
    'marker' => defined('PHPUNIT_COMPOSER_INSTALL')], JSON_THROW_ON_ERROR);
PHP;
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        try {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = proc_close($process);
        }
        self::assertSame(0, $exit, $stderr);
        self::assertSame('', $stderr);

        return json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    }

    private function migration(): string
    {
        return dirname(__DIR__, 3).'/database/migrations/2026_10_18_000002_create_pep_directory_configuration_tables.php';
    }

    public function test_sqlite_phpunit_testing_discovery_returns_without_ddl(): void
    {
        self::assertTrue(defined('PHPUNIT_COMPOSER_INSTALL'));
        self::assertSame(['outcome' => 'returned', 'message' => null,
            'schema_calls' => 0, 'sql_calls' => 0, 'marker' => true],
            self::probe($this->migration(), 'sqlite', 'testing', true));
    }

    public function test_sqlite_non_testing_rejects_without_ddl(): void
    {
        $this->assertRejected('sqlite', 'production', true);
    }

    public function test_testing_sqlite_without_phpunit_marker_rejects_without_ddl(): void
    {
        $this->assertRejected('sqlite', 'testing', false);
    }

    public function test_other_drivers_reject_even_under_phpunit_testing(): void
    {
        foreach (['mysql', 'sqlsrv'] as $driver) {
            $this->assertRejected($driver, 'testing', true);
        }
    }

    private function assertRejected(string $driver, string $environment, bool $marker): void
    {
        self::assertSame(['outcome' => \RuntimeException::class, 'message' => self::REJECTION,
            'schema_calls' => 0, 'sql_calls' => 0, 'marker' => $marker],
            self::probe($this->migration(), $driver, $environment, $marker));
    }

    public function test_postgresql_reaches_first_schema_call_in_every_context(): void
    {
        foreach (['testing', 'production'] as $environment) {
            foreach ([true, false] as $marker) {
                self::assertSame(['outcome' => \RuntimeException::class, 'message' => 'schema sentinel: table',
                    'schema_calls' => 1, 'sql_calls' => 0, 'marker' => $marker],
                    self::probe($this->migration(), 'pgsql', $environment, $marker));
            }
        }
    }

    public function test_down_always_refuses_without_ddl(): void
    {
        foreach (['pgsql', 'sqlite'] as $driver) {
            self::assertSame(['outcome' => \RuntimeException::class,
                'message' => 'Qualification history requires explicit archival review; automatic destructive rollback is prohibited.',
                'schema_calls' => 0, 'sql_calls' => 0, 'marker' => true],
                self::probe($this->migration(), $driver, 'testing', true, 'down'));
        }
    }
}
