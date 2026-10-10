<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Fuente;
use App\Models\Snapshot;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AddConfigurableAuthorityExtractionTest extends TestCase
{
    use RefreshDatabase;

    public function test_up_adds_nullable_columns_without_changing_existing_rows_and_down_removes_only_new_columns(): void
    {
        $fuente = Fuente::factory()->create(['url' => 'https://example.test/authority-migration']);
        $snapshot = Snapshot::create([
            'fuente_id' => $fuente->id,
            'hash' => str_repeat('a', 64),
            'texto' => 'existing snapshot content',
            'fecha' => now(),
        ]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $this->assertTrue(Schema::hasColumns('fuentes', [
            'authority_extraction_config',
            'authority_extraction_revision',
            'authority_extraction_confirmed_revision',
        ]));
        $this->assertTrue(Schema::hasColumns('snapshots', [
            'authority_extraction_config_fingerprint',
            'authority_extraction_revision',
        ]));
        $this->assertSame('https://example.test/authority-migration', $fuente->fresh()->url);
        $this->assertSame('existing snapshot content', $snapshot->fresh()->texto);
        $this->assertNull($fuente->fresh()->getAttribute('authority_extraction_config'));
        $this->assertNull($snapshot->fresh()->getAttribute('authority_extraction_revision'));

        $migration->down();

        $this->assertFalse(Schema::hasColumn('fuentes', 'authority_extraction_config'));
        $this->assertFalse(Schema::hasColumn('snapshots', 'authority_extraction_config_fingerprint'));
        $this->assertSame('https://example.test/authority-migration', $fuente->fresh()->url);
        $this->assertSame('existing snapshot content', $snapshot->fresh()->texto);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_20_000002_add_configurable_authority_extraction_to_fuentes_and_snapshots.php');
    }
}
