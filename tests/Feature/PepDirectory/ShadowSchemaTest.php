<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\PepDirectoryDatabaseTestCase;

final class ShadowSchemaTest extends PepDirectoryDatabaseTestCase
{
    public function test_four_tables_and_complete_wire_roundtrip_with_nullable_live_links(): void
    {
        foreach (['sources', 'observations', 'events', 'reviews'] as $suffix) {
            $this->assertTrue(Schema::hasTable('pep_directory_'.$suffix), $suffix);
        }
        [$source, $observation, $event, $review, $wire] = $this->records();
        $stored = DB::table('pep_directory_observations')->find($observation);
        $this->assertSame($wire['observation'], json_decode($stored->payload, true));
        $this->assertSame($wire['event'], json_decode(DB::table('pep_directory_events')->find($event)->payload, true));
        $this->assertSame('2026-10-18T10:11:12.123456-04:00', $stored->observed_at);
        $this->assertNull(DB::table('pep_directory_sources')->find($source)->fuente_id);
        $this->assertSame('pending', DB::table('pep_directory_events')->find($event)->review_state);
        $this->assertNotNull(DB::table('pep_directory_reviews')->find($review)->decided_at);
        $this->assertFalse(Schema::hasTable('cambios'));
    }

    public function test_distinct_unchanged_captures_survive_but_retry_and_event_review_duplicates_fail(): void
    {
        [$source, $observation, $event, $review] = $this->records();
        $row = (array) DB::table('pep_directory_observations')->find($observation);
        unset($row['id']);
        $row['submission_id'] = 'second-capture';
        DB::table('pep_directory_observations')->insert($row); // Same content hash is allowed.
        foreach (['observations' => $observation, 'events' => $event, 'reviews' => $review] as $table => $id) {
            $row = (array) DB::table('pep_directory_'.$table)->find($id);
            $original = $row;
            unset($row['id']);
            $this->refused(fn () => DB::table('pep_directory_'.$table)->insert($row));
            $this->assertSame($original, (array) DB::table('pep_directory_'.$table)->find($id));
        }
        $this->assertSame(3, DB::table('pep_directory_observations')->count());
        $this->assertSame(1, DB::table('pep_directory_events')->count());
        $this->assertSame(1, DB::table('pep_directory_reviews')->count());
        $this->assertSame(0, DB::table('pep_directory_sources')->find($source)->version);
        $this->assertSame(0, DB::table('pep_directory_events')->find($event)->review_version);
    }

    public function test_deleting_live_parents_preserves_evidence_and_attribution(): void
    {
        [$source, $observation, $event, $review] = $this->records();
        DB::table('fuentes')->insert(['id' => 1, 'label' => 'Synthetic directory']);
        DB::table('users')->insert(['id' => 1, 'label' => 'Synthetic supervisor']);
        DB::table('pep_directory_sources')->where('id', $source)->update(['fuente_id' => 1]);
        DB::table('pep_directory_reviews')->where('id', $review)->update(['user_id' => 1]);
        DB::table('fuentes')->where('id', 1)->delete();
        DB::table('users')->where('id', 1)->delete();
        $this->assertNull(DB::table('pep_directory_sources')->find($source)->fuente_id);
        $decision = DB::table('pep_directory_reviews')->find($review);
        $this->assertNull($decision->user_id);
        $this->assertSame('synthetic:supervisor:1', $decision->actor_identity);
        $this->assertSame(['name' => 'Synthetic supervisor'], json_decode($decision->actor_snapshot, true));
        $this->assertSame('synthetic:directory', DB::table('pep_directory_sources')->find($source)->identity);
        $this->assertNotNull(DB::table('pep_directory_observations')->find($observation)->payload);
        $this->assertNotNull(DB::table('pep_directory_events')->find($event)->payload);
    }

    public function test_internal_references_cannot_cross_sources_or_orphan_evidence(): void
    {
        [$source, $observation, $event] = $this->records();
        $other = DB::table('pep_directory_sources')->insertGetId(['identity' => 'other:directory']);
        $this->refused(fn () => DB::table('pep_directory_sources')->where('id', $other)->update(['baseline_observation_id' => $observation]));
        $this->refused(fn () => DB::table('pep_directory_events')->where('id', $event)->update(['source_id' => $other]));
        $this->refused(fn () => DB::table('pep_directory_observations')->where('id', $observation)->delete());
        $this->refused(fn () => DB::table('pep_directory_sources')->where('id', $source)->delete());
        $this->refused(fn () => DB::table('pep_directory_events')->where('id', $event)->delete());
        $this->assertSame(2, DB::table('pep_directory_observations')->count());
        $this->refused(fn () => DB::table('pep_directory_sources')->insert(['identity' => 'synthetic:directory']));
    }

    public function test_bootstrap_is_private_and_guard_rejects_before_connector_or_migration(): void
    {
        $this->assertSame('/tmp/pep-schema', $this->app->environmentPath());
        $this->assertSame('never-load.env', $this->app->environmentFile());
        $this->assertSame('/tmp/pep-schema/APP_CONFIG_CACHE.php', $this->app->getCachedConfigPath());
        $this->assertFalse($this->app['config']->get('gemini.enabled'));
        $this->assertArrayNotHasKey(\App\Providers\AppServiceProvider::class, $this->app->getLoadedProviders());
        $before = Schema::getTableListing();
        $calls = 0;
        $this->app['db']->extend('pgsql', static function () use (&$calls): never {
            $calls++;
            throw new \RuntimeException('Unexpected connector invocation.');
        });
        $config = $this->app['config']->get('database');
        $this->app['config']->set('database.connections.sentinel', $config['connections']['pilot']);
        $this->app['config']->set('database.default', 'sentinel');
        try {
            $this->refreshDatabase(); // Real RefreshDatabase orchestration, not a direct helper call.
            $this->fail('Expected early guard refusal.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Disposable database guard refused the effective targets.', $exception->getMessage());
        } finally {
            $this->app['config']->set('database', $config);
        }
        $this->assertSame(0, $calls);
        $this->assertSame($before, Schema::getTableListing());
    }

    public function test_rejected_observation_retains_nulls_reasons_and_provenance(): void
    {
        [$source, $observation, $event, $review, $wire] = $this->records();
        $payload = array_replace($wire['observation'], ['revision' => null, 'validity' => 'incomplete', 'positions' => [['unit' => 'Unidad Ñ', 'role' => 'Dirección', 'person' => null, 'condition' => null, 'evidence' => 'Cargo sin nombre']]]);
        $id = DB::table('pep_directory_observations')->insertGetId([
            'source_id' => $source, 'submission_id' => 'rejected-submit', 'capture_identity' => 'rejected-capture',
            'content_sha256' => hash('sha256', 'rejected'), 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'observed_at' => $payload['observed_at'], 'provenance' => '{"encoding":null,"status":200}',
            'disposition' => 'rejected', 'reasons' => '["capture_incomplete"]',
        ]);
        $stored = DB::table('pep_directory_observations')->find($id);
        $this->assertSame($payload, json_decode($stored->payload, true));
        $this->assertSame(['capture_incomplete'], json_decode($stored->reasons, true));
        $this->assertSame(['encoding' => null, 'status' => 200], json_decode($stored->provenance, true));
        $this->assertNotNull($stored->received_at);
        $this->refused(fn () => DB::table('pep_directory_reviews')->where('id', $review)->update(['event_id' => 999999]));
        $this->refused(fn () => DB::table('pep_directory_events')->where('id', $event)->update(['review_state' => 'legacy_acknowledged']));
        $this->assertSame($observation, DB::table('pep_directory_sources')->find($source)->baseline_observation_id);
    }

    public function test_public_migration_down_up_leaves_parent_tables_and_records(): void
    {
        $this->records(); // Exercise the cyclic baseline FK with populated shadow tables.
        DB::table('fuentes')->insert(['label' => 'Fixture survives']);
        DB::table('users')->insert(['label' => 'Fixture survives']);
        $migration = require '/work/'.self::MIGRATION;
        $this->assertInstanceOf(\Illuminate\Database\Migrations\Migration::class, $migration);
        $migration->down();
        foreach (['sources', 'observations', 'events', 'reviews'] as $suffix) {
            $this->assertFalse(Schema::hasTable('pep_directory_'.$suffix));
        }
        $this->assertSame(1, DB::table('fuentes')->count());
        $this->assertSame(1, DB::table('users')->count());
        $migration->up();
        $this->assertTrue(Schema::hasColumn('pep_directory_sources', 'baseline_observation_id'));
    }

    private function refused(callable $write): void
    {
        try {
            DB::transaction($write); // Savepoint: PostgreSQL errors must not poison the outer test transaction.
            $this->fail('Expected database constraint rejection.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function records(): array
    {
        $json = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $position = static fn (string $name, ?string $condition): array => ['unit' => 'Unidad Ñ', 'role' => 'Dirección', 'person' => $name, 'condition' => $condition, 'evidence' => '<b>'.$name.'</b>'];
        $ana = $position('Ana Ejemplo', null);
        $bea = $position('Beatriz Ejemplo', 'titular');
        $bei = $position('Beatriz Ejemplo', 'a.i.');
        $carla = $position('Carla Ejemplo', null);
        $time = '2026-10-18T10:11:12.123456-04:00';
        $before = '2026-10-17T14:11:12+00:00';
        $wire = [
            'observation' => ['source' => 'synthetic:directory', 'revision' => 'pilot-html-v2-groups', 'observed_at' => $time, 'validity' => 'valid', 'positions' => [$bei, $carla], 'evidence' => "<html>Ñ — evidencia\n</html>"],
            'event' => ['source' => 'synthetic:directory', 'unit' => 'Unidad Ñ', 'role' => 'Dirección', 'kind' => 'possible_replacement', 'before' => null, 'after' => null, 'previous_observed_at' => $before, 'detected_at' => $time, 'previous_evidence' => '<html>Anterior</html>', 'current_evidence' => "<html>Ñ — evidencia\n</html>", 'effective_date' => null, 'before_group' => [$ana, $bea], 'after_group' => [$bei, $carla], 'outgoing' => [$ana], 'incoming' => [$carla], 'condition_changes' => [[$bea, $bei]]],
        ];
        $source = DB::table('pep_directory_sources')->insertGetId(['identity' => 'synthetic:directory']);
        $previousPayload = array_replace($wire['observation'], ['observed_at' => $before, 'positions' => [$ana, $bea], 'evidence' => '<html>Anterior</html>']);
        $previous = DB::table('pep_directory_observations')->insertGetId([
            'source_id' => $source, 'submission_id' => 'capture-submit-0', 'capture_identity' => 'synthetic:capture:0',
            'content_sha256' => hash('sha256', 'previous'), 'provenance' => '{}', 'payload' => $json($previousPayload),
            'observed_at' => $before, 'disposition' => 'baseline', 'reasons' => '[]',
        ]);
        $observation = DB::table('pep_directory_observations')->insertGetId([
            'source_id' => $source, 'submission_id' => 'capture-submit-1', 'capture_identity' => 'synthetic:capture:1',
            'content_sha256' => hash('sha256', 'unchanged'), 'provenance' => $json(['encoding' => 'utf-8', 'status' => 200]),
            'payload' => $json($wire['observation']), 'observed_at' => $time, 'disposition' => 'compared', 'reasons' => '[]',
        ]);
        DB::table('pep_directory_sources')->where('id', $source)->update(['baseline_observation_id' => $observation]);
        $event = DB::table('pep_directory_events')->insertGetId([
            'source_id' => $source, 'previous_observation_id' => $previous, 'current_observation_id' => $observation,
            'event_index' => 0, 'payload' => $json($wire['event']),
        ]);
        $review = DB::table('pep_directory_reviews')->insertGetId([
            'event_id' => $event, 'version' => 1, 'actor_identity' => 'synthetic:supervisor:1',
            'actor_snapshot' => $json(['name' => 'Synthetic supervisor']), 'reason' => 'Synthetic decision',
            'prior_state' => 'pending', 'new_state' => 'pending',
        ]);

        return [$source, $observation, $event, $review, $wire];
    }
}
