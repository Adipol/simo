<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\PepDirectoryDatabaseTestCase;
use Throwable;

final class SchemaHarnessOptInTest extends TestCase
{
    public static function selections(): array
    {
        return [
            'absent skips' => [null, SkippedWithMessageException::class, 0],
            'explicit empty fails' => ['', RuntimeException::class, 0],
            'explicit invalid fails' => ['invalid', RuntimeException::class, 0],
            'pgsql reaches application boundary' => ['pgsql', RuntimeException::class, 1],
        ];
    }

    #[DataProvider('selections')]
    public function test_opt_in_precedes_application_and_environment_changes(?string $engine, string $expectedException, int $applications): void
    {
        $original = getenv('PEP_SCHEMA_ENGINE');
        $keys = array_fill_keys(['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'], true);
        $snapshot = static fn (): array => [
            array_map(static fn (string $key): string|false => getenv($key), array_keys($keys)),
            array_intersect_key($_ENV, $keys), array_intersect_key($_SERVER, $keys),
        ];
        $before = $snapshot();
        $probe = new class('sentinel') extends PepDirectoryDatabaseTestCase
        {
            public int $applications = 0;

            public function probe(): void
            {
                $this->setUp();
            }

            public function createApplication(): Application
            {
                $this->applications++;
                // Stop before constructing Application, bootstrapping, connecting or migrating.
                throw new RuntimeException('Application boundary reached.');
            }
        };
        $caught = null;
        try {
            putenv($engine === null ? 'PEP_SCHEMA_ENGINE' : 'PEP_SCHEMA_ENGINE='.$engine);
            try {
                $probe->probe();
            } catch (Throwable $exception) {
                $caught = $exception;
            }
            $this->assertInstanceOf($expectedException, $caught);
            $this->assertSame($applications, $probe->applications);
            $this->assertSame($before, $snapshot());
            $this->assertSame(match ($engine) {
                null => 'PEP directory schema checks require explicit PEP_SCHEMA_ENGINE=pgsql opt-in.',
                'pgsql' => 'Application boundary reached.',
                default => 'S13 authorizes PostgreSQL schema checks only.',
            }, $caught->getMessage());
        } finally {
            putenv($original === false ? 'PEP_SCHEMA_ENGINE' : 'PEP_SCHEMA_ENGINE='.$original);
        }
    }
}
