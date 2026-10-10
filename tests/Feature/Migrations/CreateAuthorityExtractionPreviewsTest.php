<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\AuthorityExtractionPreview;
use App\Models\Fuente;
use App\Models\Snapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CreateAuthorityExtractionPreviewsTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_has_one_current_preview_per_source_and_preserves_source_and_snapshot_on_rollback(): void
    {
        $fuente = Fuente::factory()->create();
        $other = Fuente::factory()->create();
        $snapshot = Snapshot::create([
            'fuente_id' => $fuente->id,
            'hash' => str_repeat('a', 64),
            'texto' => 'previous baseline',
            'fecha' => now(),
        ]);
        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('authority_extraction_previews', [
            'fuente_id', 'source_revision', 'source_url', 'status', 'attempts',
            'lease_token', 'lease_expires_at', 'config_fingerprint', 'authorities_json',
            'error_code', 'requested_at', 'completed_at', 'created_at', 'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumn('fuentes', 'authority_extraction_revision'));
        $this->assertTrue(Schema::hasColumn('snapshots', 'authority_extraction_config_fingerprint'));

        $preview = $this->preview($fuente);
        $this->assertSame(0, $preview->fresh()->attempts);
        $this->preview($other);
        try {
            DB::transaction(fn () => $this->preview($fuente));
            $this->fail('A second current preview for one source must be rejected.');
        } catch (QueryException) {
            $this->assertSame(2, AuthorityExtractionPreview::count());
        }

        $migration->down();
        $this->assertFalse(Schema::hasTable('authority_extraction_previews'));
        $this->assertSame($fuente->url, $fuente->fresh()->url);
        $this->assertSame('previous baseline', $snapshot->fresh()->texto);
        $this->assertTrue(Schema::hasColumn('fuentes', 'authority_extraction_revision'));
        $this->assertTrue(Schema::hasColumn('snapshots', 'authority_extraction_config_fingerprint'));
    }

    public function test_postgres_rejects_negative_attempts_and_unknown_status(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL CHECK constraints are exercised in the PG verification harness.');
        }

        $preview = $this->preview(Fuente::factory()->create());
        foreach (['attempts' => -1, 'status' => 'unknown'] as $column => $invalid) {
            try {
                DB::transaction(fn () => $preview->update([$column => $invalid]));
                $this->fail('Invalid preview state must be rejected by the database.');
            } catch (QueryException) {
                $this->assertSame('pending', $preview->fresh()->status);
                $this->assertSame(0, $preview->fresh()->attempts);
            }
        }
    }

    public function test_model_casts_json_dates_and_attempts_and_relates_to_source(): void
    {
        $fuente = Fuente::factory()->create();
        $preview = $this->preview($fuente);
        $preview->update([
            'authorities_json' => [['person' => 'Ada', 'role' => 'Director']],
            'lease_expires_at' => now(),
            'completed_at' => now(),
            'attempts' => 2,
        ]);

        $reloaded = $preview->fresh();
        $this->assertSame([['person' => 'Ada', 'role' => 'Director']], $reloaded->authorities_json);
        $this->assertSame(2, $reloaded->attempts);
        $this->assertNotNull($reloaded->lease_expires_at);
        $this->assertNotNull($reloaded->completed_at);
        $this->assertTrue($reloaded->fuente->is($fuente));
    }

    private function preview(Fuente $fuente): AuthorityExtractionPreview
    {
        return AuthorityExtractionPreview::create([
            'fuente_id' => $fuente->id,
            'source_revision' => '11111111-1111-4111-8111-111111111111',
            'source_url' => $fuente->url,
            'status' => 'pending',
            'requested_at' => now(),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_20_000003_create_authority_extraction_previews_table.php');
    }
}
