<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

require_once __DIR__.'/../../Support/GuardsDisposableDatabase.php';
require_once __DIR__.'/../../Support/PepDirectoryReviewTestCase.php';

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Tests\Support\PepDirectoryReviewTestCase;

final class ConfigurationSchemaTest extends PepDirectoryReviewTestCase
{
    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->db)) {
                self::assertNull($this->db->selectOne("SELECT to_regprocedure('public.pep_invalidate_source()') AS name")->name);
                self::assertSame(0, (int) $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_trigger WHERE tgname = 'pep_fuente_invalidate'")->n);
                echo "FUNCTION/TRIGGER ABSENT AFTER ROLLBACK\n";
            }
        }
    }

    public function test_public_migration_and_registered_fuente_invalidation(): void
    {
        $path = __DIR__.'/../../../database/migrations/2026_10_18_000002_create_pep_directory_configuration_tables.php';
        self::assertFileExists($path, 'Public qualification migration must exist.');
        // Only fixture overlay: the inherited strict six-table check already ran.
        $this->db->getSchemaBuilder()->table('fuentes', function (Blueprint $table): void {
            $table->text('url')->nullable();
            $table->string('tipo', 32)->nullable();
            $table->boolean('activo')->default(true);
        });
        (require $path)->up();
        $this->assertMetadata();
        $fuente = $this->db->table('fuentes')->insertGetId(['label' => 'synthetic qualification', 'url' => 'https://example.invalid/a', 'tipo' => 'directorio']);
        $source = $this->db->table('pep_directory_sources')->insertGetId(['identity' => 'qualification-test', 'fuente_id' => $fuente, 'configurable' => true]);
        $other = $this->db->table('pep_directory_sources')->insertGetId(['identity' => 'qualification-other']);
        $row = $this->source($source);
        self::assertSame(0, (int) $row->eligibility_version);
        self::assertNull($row->approved_decision_id);
        self::assertFalse((bool) $this->source($other)->configurable);
        $revision = 'configured-html-v1:'.str_repeat('a', 64);
        $config = $this->db->table('pep_directory_configurations')->insertGetId(['source_id' => $source, 'declaration' => '{}', 'canonical_bytes' => '{}', 'revision' => $revision, 'binding_url' => 'https://example.invalid/a', 'directory_url' => 'https://example.invalid/a', 'creator_identity' => 'synthetic', 'creator_snapshot' => '{}']);
        self::assertSame($revision, $this->db->table('pep_directory_configurations')->value('revision'));
        $capture = $this->db->table('pep_directory_captures')->insertGetId(['source_id' => $source, 'body_bytes' => '<table/>', 'content_sha256' => str_repeat('a', 64), 'metadata' => '{}', 'provenance' => '{}', 'captured_at' => '2026-10-18 00:00:00+00']);
        $preview = $this->db->table('pep_directory_previews')->insertGetId(['source_id' => $source, 'configuration_id' => $config, 'capture_id' => $capture, 'result_bytes' => '{}', 'result_sha256' => str_repeat('b', 64), 'wire_payload' => '{}', 'validity' => 'valid', 'qualification' => '{}', 'eligibility_version' => 0]);
        $approval = $this->db->table('pep_directory_configuration_decisions')->insertGetId(['source_id' => $source, 'sequence' => 1, 'action' => 'approve', 'configuration_id' => $config, 'preview_id' => $preview, 'actor_kind' => 'human', 'actor_identity' => 'synthetic', 'actor_snapshot' => '{}', 'reason' => 'fixture, not service approval']);
        $observation = $this->db->table('pep_directory_observations')->insertGetId(['source_id' => $source, 'submission_id' => 'synthetic', 'capture_identity' => 'synthetic', 'content_sha256' => str_repeat('c', 64), 'provenance' => '{}', 'payload' => '{}', 'observed_at' => 'synthetic', 'disposition' => 'baseline', 'reasons' => '[]', 'configuration_id' => $config, 'capture_id' => $capture, 'approval_decision_id' => $approval]);
        $this->db->table('pep_directory_sources')->where('id', $source)->update(['eligibility_version' => 1, 'draft_config_id' => $config, 'current_preview_id' => $preview, 'approved_decision_id' => $approval, 'baseline_observation_id' => $observation, 'version' => 7]);
        foreach (['draft_config_id' => $config, 'current_preview_id' => $preview, 'approved_decision_id' => $approval] as $column => $value) {
            $this->reject(fn () => $this->db->table('pep_directory_sources')->where('id', $other)->update([$column => $value]));
        }
        $otherObservation = $this->db->table('pep_directory_observations')->insertGetId(['source_id' => $other, 'submission_id' => 'synthetic-other', 'capture_identity' => 'synthetic-other', 'content_sha256' => str_repeat('d', 64), 'provenance' => '{}', 'payload' => '{}', 'observed_at' => 'synthetic', 'disposition' => 'baseline', 'reasons' => '[]']);
        foreach (['configuration_id' => $config, 'capture_id' => $capture, 'approval_decision_id' => $approval] as $column => $value) {
            $this->reject(fn () => $this->db->table('pep_directory_observations')->where('id', $otherObservation)->update([$column => $value]));
        }
        $this->reject(fn () => $this->db->table('pep_directory_previews')->where('id', $preview)->update(['source_id' => $other]));
        $this->reject(fn () => $this->db->table('pep_directory_configuration_decisions')->where('id', $approval)->update(['source_id' => $other]));
        $this->reject(fn () => $this->db->table('pep_directory_sources')->insert(['identity' => 'duplicate', 'fuente_id' => $fuente, 'configurable' => true]));
        $this->reject(fn () => $this->db->table('pep_directory_configurations')->where('id', $config)->delete());
        $this->reject(fn () => $this->db->table('pep_directory_sources')->where('id', $source)->update(['eligibility_version' => -1]));
        $before = $this->source($source);
        $this->mutate($fuente, ['label' => 'metadata only']);
        self::assertEquals($before, $this->source($source));
        // A conflicting lifecycle receipt must roll back the Fuente statement atomically.
        $this->db->beginTransaction();
        $this->db->table('pep_directory_configuration_decisions')->insert(['source_id' => $source, 'sequence' => 2, 'action' => 'synthetic-conflict', 'actor_kind' => 'system', 'actor_identity' => 'synthetic', 'actor_snapshot' => '{}', 'reason' => 'savepoint conflict']);
        $this->reject(fn () => $this->mutate($fuente, ['activo' => false]));
        self::assertEquals($before, $this->source($source));
        self::assertTrue((bool) $this->db->table('fuentes')->where('id', $fuente)->value('activo'));
        $this->db->rollBack();
        $this->mutate($fuente, ['activo' => false]);
        self::assertSame($config, (int) $this->source($source)->draft_config_id);
        $this->assertInvalidated($source, 2, $observation);
        $this->mutate($fuente, ['activo' => false]);
        $this->mutate($fuente, ['activo' => true]);
        $this->assertInvalidated($source, 2, $observation);
        foreach ([['url' => 'https://example.invalid/b'], ['url' => 'https://example.invalid/a'], ['tipo' => 'otro']] as $i => $change) {
            $this->mutate($fuente, $change);
            $this->assertInvalidated($source, $i + 3, $observation);
            self::assertNull($this->source($source)->draft_config_id);
        }
        $this->db->table('fuentes')->where('id', $fuente)->delete();
        $this->assertInvalidated($source, 6, $observation);
        self::assertNull($this->source($source)->fuente_id);
        $decisions = $this->db->table('pep_directory_configuration_decisions')->where('source_id', $source)->orderBy('sequence')->get();
        self::assertSame([1, 2, 3, 4, 5, 6], $decisions->pluck('sequence')->map(fn ($v): int => (int) $v)->all());
        foreach ($decisions->slice(1) as $decision) {
            self::assertSame('system', $decision->actor_kind);
            self::assertSame('fuente-invalidation', $decision->actor_identity);
            self::assertSame('invalidate', $decision->action);
        }
        self::assertSame($approval, (int) $decisions[1]->prior_approval_id);
        self::assertSame(1, $this->db->table('pep_directory_configurations')->count());
        $unregistered = $this->db->table('fuentes')->insertGetId(['label' => 'unregistered']);
        $this->db->table('pep_directory_sources')->where('id', $other)->update(['fuente_id' => $unregistered]);
        $unchanged = $this->source($other);
        $this->mutate($unregistered, ['url' => 'https://example.invalid/unregistered', 'activo' => false]);
        self::assertEquals($unchanged, $this->source($other));
        self::assertSame(6, $this->db->table('pep_directory_configuration_decisions')->count());
    }

    private function assertMetadata(): void
    {
        $contract = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PepDirectory/schema-contracts/contract.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($contract['v2_design']['tables'] as $table => $spec) {
            // users has the inherited authorization overlay, intentionally outside this migration.
            if ($table === 'users') {
                continue;
            }
            $columns = $this->db->select('SELECT column_name, is_nullable, column_default FROM information_schema.columns WHERE table_schema = ? AND table_name = ?', ['public', $table]);
            $actual = array_column(array_map(fn (object $row): array => (array) $row, $columns), null, 'column_name');
            self::assertSameSize($spec['columns'], $actual, $table);
            foreach ($spec['columns'] as $name => [$type, $nullable, $default]) {
                self::assertArrayHasKey($name, $actual);
                self::assertSame($nullable, $actual[$name]['is_nullable'] === 'YES', $table.'.'.$name);
                $observedDefault = $actual[$name]['column_default'];
                if ($observedDefault === "'0'::bigint") {
                    $observedDefault = '0'; // Contract's normalized bigint default.
                }
                self::assertSame($default, $observedDefault, $table.'.'.$name);
            }
            $types = $this->db->select('SELECT a.attname, pg_catalog.format_type(a.atttypid, a.atttypmod) AS type FROM pg_catalog.pg_attribute a WHERE a.attrelid = ?::regclass AND a.attnum > 0 AND NOT a.attisdropped', ['public.'.$table]);
            foreach ($types as $column) {
                $normalized = str_replace(['character varying', 'character', 'timestamp(6) with time zone'], ['varchar', 'char', 'timestamptz(6)'], $column->type);
                self::assertSame($spec['columns'][$column->attname][0], $normalized, $table.'.'.$column->attname);
            }
            $constraints = $this->db->select('SELECT conname FROM pg_catalog.pg_constraint WHERE conrelid = ?::regclass', ['public.'.$table]);
            $names = array_column($constraints, 'conname');
            foreach (array_keys($spec['constraints']) as $name) {
                self::assertContains($name, $names);
            }
            $indexes = array_column($this->db->select('SELECT indexname FROM pg_catalog.pg_indexes WHERE schemaname = ? AND tablename = ?', ['public', $table]), 'indexname');
            foreach (array_keys($spec['indexes']) as $name) {
                self::assertContains($name, $indexes);
            }
        }
        $function = $this->db->selectOne("SELECT p.prosecdef, p.proconfig FROM pg_catalog.pg_proc p JOIN pg_catalog.pg_namespace n ON n.oid = p.pronamespace WHERE n.nspname = 'public' AND p.proname = 'pep_invalidate_source'");
        self::assertFalse((bool) $function->prosecdef);
        self::assertStringContainsString('search_path=pg_catalog, public', $function->proconfig);
    }

    private function source(int $id): object
    {
        return $this->db->table('pep_directory_sources')->where('id', $id)->first();
    }

    private function mutate(int $id, array $changes): void
    {
        $this->db->table('fuentes')->where('id', $id)->update($changes);
    }

    private function assertInvalidated(int $id, int $version, int $baseline): void
    {
        $row = $this->source($id);
        self::assertSame($version, (int) $row->eligibility_version);
        self::assertNull($row->approved_decision_id);
        self::assertNull($row->current_preview_id);
        self::assertSame($baseline, (int) $row->baseline_observation_id);
        self::assertSame(7, (int) $row->version);
    }

    private function reject(callable $operation): void
    {
        $this->db->beginTransaction();
        try {
            $operation();
            self::fail('Constraint must reject operation.');
        } catch (QueryException $e) {
            self::assertStringStartsWith('23', (string) $e->getCode());
        } finally {
            $this->db->rollBack();
        }
    }
}
