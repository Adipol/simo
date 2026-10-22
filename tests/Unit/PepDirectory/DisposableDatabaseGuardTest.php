<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\GuardsDisposableDatabase;

final class DisposableDatabaseGuardTest extends TestCase
{
    private const REFUSAL = 'Disposable database guard refused the effective targets.';

    /** @return array<string, array{array, array, array, array}> */
    public static function refusedTargets(): array
    {
        $pg = self::postgres();
        $safe = self::configuration($pg);
        $pins = ['pilot' => $pg];
        $withSecondary = $safe;
        $withSecondary['connections']['secondary'] = array_replace($pg, ['database' => 'reference']);
        $secondaryPins = $pins + ['secondary' => array_replace($pg, ['database' => 'second_disposable'])];

        return [
            'URL host redirection' => [self::configuration($pg + ['url' => 'postgres://reference.invalid:5432/pilot_disposable']), [null], [], $pins],
            'URL query overrides primary identity' => [self::configuration($pg + ['url' => 'postgres://sandbox.invalid:5432/pilot_disposable?database=reference']), [null], [], $pins],
            'cached effective config overrides environment intent' => [self::configuration(array_replace($pg, ['database' => 'cached_reference'])), [null], [], $pins],
            'unsafe secondary transaction' => [$withSecondary, [null, 'secondary'], [], $secondaryPins],
            'missing secondary transaction' => [$safe, [null, 'secondary'], [], $secondaryPins],
            'transaction target does not protect default migration' => [['default' => 'reference', 'connections' => ['reference' => array_replace($pg, ['database' => 'reference']), 'pilot' => $pg]], ['pilot'], [], $pins],
            'unsafe explicit migration override' => [$withSecondary, [null], ['--database' => 'secondary'], $secondaryPins],
            'unallowlisted explicit migration override' => [$withSecondary, [null], ['--database' => 'secondary'], $pins],
            'safe override cannot excuse unsafe default' => [['default' => 'secondary', 'connections' => $withSecondary['connections']], ['pilot'], ['--database' => 'pilot'], $secondaryPins],
            'missing default' => [['connections' => ['pilot' => $pg]], ['pilot'], [], $pins],
            'missing connection' => [['default' => 'pilot', 'connections' => []], [null], [], $pins],
            'unknown driver' => [self::configuration(['driver' => 'mysql', 'database' => 'pilot_disposable']), [null], [], $pins],
            'read endpoint' => [self::configuration($pg + ['read' => ['host' => 'reference.invalid']]), [null], [], $pins],
            'write endpoint' => [self::configuration($pg + ['write' => null]), [null], [], $pins],
            'URL read endpoint' => [self::configuration($pg + ['url' => 'postgres://sandbox.invalid:5432/pilot_disposable?read[host]=reference.invalid']), [null], [], $pins],
            'missing port' => [self::configuration(['driver' => 'pgsql', 'host' => 'sandbox.invalid', 'database' => 'pilot_disposable']), [null], [], $pins],
            'wrong port' => [self::configuration(array_replace($pg, ['port' => 5433])), [null], [], $pins],
            'multi host' => [self::configuration(array_replace($pg, ['host' => ['sandbox.invalid', 'reference.invalid']])), [null], [], $pins],
            'connector database override' => [self::configuration($pg + ['connect_via_database' => 'reference']), [null], [], $pins],
            'connector port override' => [self::configuration($pg + ['connect_via_port' => 5433]), [null], [], $pins],
            'DSN injection' => [self::configuration(array_replace($pg, ['host' => 'sandbox.invalid;dbname=reference'])), [null], [], $pins],
            'unknown configuration option' => [self::configuration($pg + ['options' => []]), [null], [], $pins],
            'DSN injection in charset' => [self::configuration($pg + ['charset' => "utf8';host=reference.invalid"]), [null], [], $pins],
            'DSN injection in sslmode' => [self::configuration($pg + ['sslmode' => 'prefer;host=reference.invalid']), [null], [], $pins],
            'missing whole connection config' => [['default' => 'pilot'], [null], [], $pins],
            'malformed URL' => [self::configuration($pg + ['url' => 'postgres://[']), [null], [], $pins],
            'missing independent pins' => [$safe, [null], [], []],
            'incomplete pin' => [$safe, [null], [], ['pilot' => ['driver' => 'pgsql', 'database' => 'pilot_disposable']]],
            'empty transactions' => [$safe, [], [], $pins],
            'unknown transaction alias' => [$safe, ['pilot::read'], [], $pins],
            'empty migration alias' => [$safe, [null], ['--database' => ''], $pins],
            'non string migration alias' => [$safe, [null], ['--database' => ['pilot']], $pins],
            'sqlite different filename' => [self::configuration(['driver' => 'sqlite', 'database' => '/reference.sqlite']), [null], [], ['pilot' => ['driver' => 'sqlite', 'database' => '/isolated/pilot.sqlite']]],
            'sqlite memory is not file' => [self::configuration(['driver' => 'sqlite', 'database' => ':memory:']), [null], [], ['pilot' => ['driver' => 'sqlite', 'database' => '/isolated/pilot.sqlite']]],
            'sqlite missing filename' => [self::configuration(['driver' => 'sqlite']), [null], [], ['pilot' => ['driver' => 'sqlite', 'database' => ':memory:']]],
        ];
    }

    #[DataProvider('refusedTargets')]
    public function test_refusal_precedes_all_migration_and_connector_callbacks(array $database, array $transactions, array $options, array $pins): void
    {
        $events = [];
        $harness = $this->harness($database, $transactions, $options, $pins, $events);
        try {
            $harness->refreshDatabase();
            self::fail('Expected refusal before any continuation.');
        } catch (RuntimeException $exception) {
            self::assertSame(RuntimeException::class, $exception::class);
            self::assertSame(self::REFUSAL, $exception->getMessage());
        }
        self::assertSame([], $events, 'No migration, connector/PDO or other safeguard ran.');
    }

    /** @return array<string, array{array, array, array, array}> */
    public static function permittedTargets(): array
    {
        $pg = self::postgres();
        $sqlite = ['driver' => 'sqlite', 'database' => ':memory:'];
        $file = ['driver' => 'sqlite', 'database' => '/isolated/pilot.sqlite'];

        return [
            'exact PostgreSQL with null and named default aliases' => [self::configuration($pg), [null, 'pilot'], [], ['pilot' => $pg]],
            'URL resolves exactly including driver alias and query' => [self::configuration(['url' => 'postgresql://sandbox.invalid:5432/unused?database=pilot_disposable']), [null], [], ['pilot' => $pg]],
            'explicit null migration alias' => [self::configuration($sqlite), [null], ['--database' => null], ['pilot' => $sqlite]],
            'exact sqlite file' => [self::configuration($file), ['pilot'], [], ['pilot' => $file]],
            'sqlite memory URL' => [self::configuration(['url' => 'sqlite:///:memory:']), [null], [], ['pilot' => $sqlite]],
            'string URL connection' => [['default' => 'pilot', 'connections' => ['pilot' => 'postgres://sandbox.invalid:5432/pilot_disposable']], [null], [], ['pilot' => $pg]],
            'identity pin key order irrelevant' => [self::configuration($pg), [null], [], ['pilot' => array_reverse($pg, true)]],
            'separate explicitly pinned migration and transaction targets' => [['default' => 'pilot', 'connections' => ['pilot' => $sqlite, 'migration' => $file]], [null], ['--database' => 'migration'], ['pilot' => $sqlite, 'migration' => $file]],
            'canonical string port' => [self::configuration(array_replace($pg, ['port' => '5432'])), [null], [], ['pilot' => $pg]],
        ];
    }

    #[DataProvider('permittedTargets')]
    public function test_exact_pins_permit_the_real_refresh_hook_continuation(array $database, array $transactions, array $options, array $pins): void
    {
        $events = [];
        $this->harness($database, $transactions, $options, $pins, $events)->refreshDatabase();
        self::assertSame(['existing safeguard', 'connector/PDO sentinel', 'migration sentinel', 'transaction connector/PDO sentinel', 'after refresh'], $events);
    }

    private static function postgres(): array
    {
        return ['driver' => 'pgsql', 'host' => 'sandbox.invalid', 'port' => 5432, 'database' => 'pilot_disposable'];
    }

    private static function configuration(array $connection): array
    {
        return ['default' => 'pilot', 'connections' => ['pilot' => $connection]];
    }

    private function harness(array $database, array $transactions, array $options, array $pins, array &$events): object
    {
        return new class($database, $transactions, $options, $pins, static function (string $event) use (&$events): void { $events[] = $event; }) {
            use RefreshDatabase;
            use GuardsDisposableDatabase;

            public function __construct(private array $database, private array $transactions, private array $options, private array $pins, private Closure $record)
            {
            }

            protected function beforeRefreshingDatabase(): void
            {
                $this->assertDisposableDatabase($this->database, $this->transactions, $this->options, $this->pins);
                ($this->record)('existing safeguard');
            }

            protected function usingInMemoryDatabases(): bool
            {
                ($this->record)('connector/PDO sentinel');
                return false;
            }

            protected function refreshTestDatabase(): void
            {
                ($this->record)('migration sentinel');
                ($this->record)('transaction connector/PDO sentinel');
            }

            protected function afterRefreshingDatabase(): void
            {
                ($this->record)('after refresh');
            }
        };
    }
}
