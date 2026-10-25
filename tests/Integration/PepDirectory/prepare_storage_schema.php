<?php

declare(strict_types=1);

// Explicit opt-in precedes framework/autoload/application/connector work.
if (getenv('PEP_SCHEMA_ENGINE') === false) {
    fwrite(STDOUT, "SKIP: explicit PEP_SCHEMA_ENGINE=pgsql required\n");
    exit(0);
}
if (getenv('PEP_SCHEMA_ENGINE') !== 'pgsql' || ($argv[1] ?? '') !== 'install') {
    fwrite(STDERR, "Requires PEP_SCHEMA_ENGINE=pgsql and argument install\n");
    exit(2);
}
require '/work/vendor/autoload.php';

final class StorageSchemaPreparation extends \Tests\Support\PepDirectoryDatabaseTestCase
{
    public function install(): void
    {
        $this->app = $this->createApplication();
        $this->beforeRefreshingDatabase(); // Existing verified guard and selected identity first.
        $db = $this->app['db']->connection('pilot');
        if ($db->selectOne('SELECT current_user AS name')->name !== 'postgres') {
            throw new \RuntimeException('Owned storage target user mismatch.');
        }
        $db->transaction(function () use ($db): void {
            $db->statement("SET LOCAL statement_timeout = '5s'");
            $db->statement("SET LOCAL lock_timeout = '5s'");
            if ($db->getSchemaBuilder()->getTableListing() !== []) {
                throw new \RuntimeException('Owned empty storage target required; no changes made.');
            }
            foreach (['tests/Fixtures/PepDirectory/migrations/0000_01_01_000001_create_shadow_test_parents.php', self::MIGRATION] as $path) {
                (require '/work/'.$path)->up();
            }
            $expected = ['fuentes', 'pep_directory_events', 'pep_directory_observations', 'pep_directory_reviews', 'pep_directory_sources', 'users'];
            $actual = array_map(static fn (object $row): string => $row->tablename,
                $db->select("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename"));
            if ($actual !== $expected) {
                throw new \RuntimeException('Unexpected installed inventory; transaction rolled back.');
            }
        });
        fwrite(STDOUT, json_encode(['target' => self::TARGET, 'installed' => [
            'tests/Fixtures/PepDirectory/migrations/0000_01_01_000001_create_shadow_test_parents.php', self::MIGRATION,
        ], 'state' => 'committed synthetic empty tables; no migration ledger'], JSON_THROW_ON_ERROR)."\n");
        $db->disconnect();
    }
}

(new StorageSchemaPreparation('storage-schema-preparation'))->install();
