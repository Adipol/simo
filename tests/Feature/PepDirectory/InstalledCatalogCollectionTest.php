<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

require_once __DIR__.'/../../Support/GuardsDisposableDatabase.php';
require_once __DIR__.'/../../Support/PepDirectoryReviewTestCase.php';
require_once __DIR__.'/../../Support/PepDirectoryInstalledCatalog.php';

use Illuminate\Database\Schema\Blueprint;
use Tests\Support\PepDirectoryInstalledCatalog;
use Tests\Support\PepDirectoryReviewTestCase;

/** Collection coverage only: no expected installed profile or write authorization. */
final class InstalledCatalogCollectionTest extends PepDirectoryReviewTestCase
{
    protected function usesAuthorizationOverlay(): bool
    {
        return false;
    }

    private function sourceAnchors(): string
    {
        $root = __DIR__.'/../../../database/migrations/';
        self::assertSame('63975cd489be968dd6547d17c2170e7db73bb80858a41980b499602f5aa4b548', hash_file('sha256', $root.'2026_10_18_000001_create_pep_directory_shadow_tables.php'));
        $v2 = $root.'2026_10_18_000002_create_pep_directory_configuration_tables.php';
        self::assertSame('b44245597fd7a9c6742c65e7bacaa8f9f8d21c0a4a41e75fa396e9b9572d7215', hash_file('sha256', $v2));

        return $v2;
    }

    private function named(array $rows, string $identity): array
    {
        $matches = array_values(array_filter($rows, static fn (array $row): bool => $row['identity'] === $identity));
        self::assertCount(1, $matches, $identity);

        return $matches[0];
    }

    public function test_v1_native_collection_is_read_only_and_source_anchored(): void
    {
        $this->sourceAnchors(); // Reviewed files, not observed DB output, are the anchors.
        $before = $this->db->transactionLevel();
        $catalog = PepDirectoryInstalledCatalog::collect($this->db);
        self::assertSame($before, $this->db->transactionLevel());
        self::assertSame(self::TABLES, array_column(array_values(array_filter($catalog['relations'], static fn (array $r): bool => $r['kind'] === 'r')), 'name'));
        self::assertSame([], $catalog['routines']);
        self::assertSame([], array_values(array_filter($catalog['triggers'], static fn (array $t): bool => !$t['internal'])));
        $column = $this->named($catalog['columns'], 'public.pep_directory_sources.version');
        self::assertSame('bigint', $column['type']);
        self::assertSame("'0'::bigint", $column['default']); // Native typed constant; never strip its cast.
        self::assertTrue($column['not_null']);
        self::assertSame(5, $column['ordinal']);
        self::assertSame('character varying(255)', $this->named($catalog['columns'], 'public.pep_directory_sources.identity')['type']);
        self::assertFalse($this->named($catalog['columns'], 'public.pep_directory_sources.fuente_id')['not_null']);
        $table = $this->named($catalog['relations'], 'public.pep_directory_sources');
        self::assertSame('postgres', $table['owner']);
        self::assertFalse($table['rls']);
        self::assertFalse($table['partition']);
        self::assertSame('p', $table['persistence']);
        $sequence = $this->named($catalog['sequences'], 'public.pep_directory_sources_id_seq');
        self::assertSame(1, $sequence['start']);
        self::assertSame(1, $sequence['increment']);
        self::assertFalse($sequence['cycle']);
        self::assertSame(['public.pep_directory_sources.id'], $sequence['ownership']);
        self::assertSame([], $catalog['policies']);
        $fk = $this->named($catalog['constraints'], 'public.pep_directory_sources.pep_source_baseline_fk');
        self::assertSame('FOREIGN KEY (id, baseline_observation_id) REFERENCES pep_directory_observations(source_id, id) ON DELETE RESTRICT', $fk['definition']);
        self::assertTrue($fk['validated']);
        self::assertFalse($fk['deferrable']);
        $index = $this->named($catalog['indexes'], 'public.pep_event_source_review_idx');
        self::assertSame('CREATE INDEX pep_event_source_review_idx ON public.pep_directory_events USING btree (source_id, review_state)', $index['definition']);
        self::assertTrue($index['valid']);
        self::assertTrue($index['ready']);
        self::assertFalse($index['unique']);
        foreach ($catalog['triggers'] as $trigger) {
            self::assertTrue($trigger['internal']);
            self::assertSame('O', $trigger['enabled']);
            self::assertNotEmpty($trigger['constraint']);
        }
        self::assertSame($catalog, PepDirectoryInstalledCatalog::collect($this->db));
    }

    public function test_temporary_v2_recreation_has_identical_metadata_without_oid_names(): void
    {
        $path = $this->sourceAnchors();
        $source = file_get_contents($path);
        $body = explode('$$;', explode('AS $$', $source, 2)[1], 2)[0];
        $snapshots = [];
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->db->beginTransaction(); // Only our transactional overlay is rolled back.
            $this->db->getSchemaBuilder()->table('fuentes', static function (Blueprint $table): void {
                $table->text('url')->nullable();
                $table->string('tipo', 32)->nullable();
                $table->boolean('activo')->default(true);
            });
            (require $path)->up();
            $snapshots[] = $catalog = PepDirectoryInstalledCatalog::collect($this->db);
            self::assertCount(10, array_filter($catalog['relations'], static fn (array $r): bool => $r['kind'] === 'r'));
            $routine = $this->named($catalog['routines'], 'public.pep_invalidate_source()');
            self::assertSame('f', $routine['kind']);
            self::assertSame('plpgsql', $routine['language']);
            self::assertSame('postgres', $routine['owner']);
            self::assertFalse($routine['security_definer']);
            self::assertSame(['search_path=pg_catalog, public'], $routine['settings']);
            // Exact body independently sliced from the hash-pinned reviewed source before DB comparison.
            self::assertSame($body, $routine['source']);
            self::assertStringContainsString($body, $routine['definition']);
            $trigger = $this->named($catalog['triggers'], 'public.fuentes.pep_fuente_invalidate');
            self::assertSame('CREATE TRIGGER pep_fuente_invalidate BEFORE DELETE OR UPDATE ON public.fuentes FOR EACH ROW EXECUTE FUNCTION pep_invalidate_source()', $trigger['definition']);
            self::assertSame('public.pep_invalidate_source()', $trigger['function']);
            self::assertSame(27, $trigger['flags']);
            self::assertSame('O', $trigger['enabled']);
            self::assertSame('postgres', $trigger['relation_owner']);
            self::assertSame("'0'::bigint", $this->named($catalog['columns'], 'public.pep_directory_sources.eligibility_version')['default']);
            self::assertSame('((configurable = true) AND (fuente_id IS NOT NULL))', $this->named($catalog['indexes'], 'public.pep_src_live_uq')['predicate']);
            $this->db->rollBack();
            self::assertSame(1, $this->db->transactionLevel());
        }
        self::assertSame($snapshots[0], $snapshots[1], 'Recreation must not expose allocated object identities.');
    }

    public function test_unsupported_collection_context_and_shape_fail_closed(): void
    {
        foreach (['driver', 'version', 'namespace', 'shape'] as $case) {
            $connection = $this->createMock(\Illuminate\Database\Connection::class);
            $connection->method('getDriverName')->willReturn($case === 'driver' ? 'sqlite' : 'pgsql');
            if ($case === 'driver') {
                $connection->expects(self::never())->method('selectOne');
            } else {
                $context = (object) ['version' => $case === 'version' ? 160000 : 170000,
                    'actor' => 'postgres', 'schemas' => $case === 'namespace' ? '{other,public}' : '{public}'];
                $connection->expects(self::exactly($case === 'shape' ? 2 : 1))->method('selectOne')
                    ->willReturnOnConsecutiveCalls($context, (object) ['catalog' => '{}']);
            }
            try {
                PepDirectoryInstalledCatalog::collect($connection);
                self::fail('Unsupported '.$case.' was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString($case === 'driver' ? 'PostgreSQL 17' : ($case === 'shape' ? 'envelope' : 'Unsupported catalog'), $error->getMessage());
            }
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown(); // Original rows, column inventory and six tables reattested after outer rollback.
        if (isset($this->db)) {
            self::assertNull($this->db->selectOne("SELECT pg_catalog.to_regprocedure('public.pep_invalidate_source()') AS name")->name);
            self::assertSame(0, (int) $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_trigger WHERE tgname = 'pep_fuente_invalidate'")->n);
            echo "FUNCTION/TRIGGER ABSENT AFTER OUTER ROLLBACK\n";
        }
    }
}
