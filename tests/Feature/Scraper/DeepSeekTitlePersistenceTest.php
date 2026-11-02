<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Models\ResultadoScraping;
use App\Services\Scraper\DeepSeekTitleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DeepSeekTitlePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_migration_leaves_existing_article_untouched(): void
    {
        $files = glob(database_path('migrations/*_add_deepseek_title_gate_to_resultados_scraping.php'));
        $this->assertCount(1, $files, 'The additive title-gate migration must exist.');
        $migration = require $files[0];
        $migration->down();
        $record = ResultadoScraping::factory()->create(['notas' => 'Conservar']);
        $before = $record->fresh()->getRawOriginal();
        $migration->up();
        $after = $record->fresh()->getRawOriginal();
        foreach ($before as $key => $value) {
            $this->assertSame($value, $after[$key], $key);
        }
        foreach ($this->columns() as $column) {
            $this->assertTrue(Schema::hasColumn('resultados_scraping', $column));
            $this->assertNull($after[$column]);
        }
    }

    public function test_public_eloquent_update_changes_only_gate_fields(): void
    {
        config(['services.deepseek_title_gate' => [
            'enabled' => true, 'api_key' => 'fake-key', 'model' => 'deepseek-flash',
            'activation_after_id' => 0, 'timeout' => 20, 'batch_size' => 1,
        ]]);
        $record = ResultadoScraping::factory()->create([
            'titulo' => 'Un partido amistoso', 'leido' => true, 'relevante' => true,
            'descartado' => false, 'archivado_at' => now(), 'notas' => 'Manual',
        ]);
        $record->personas()->create([
            'nombre' => 'Persona de prueba', 'cargo' => 'Ministro', 'categoria' => 'PEP',
            'confianza' => 90, 'threshold_passed' => true,
        ]);
        $before = $record->fresh()->getRawOriginal();
        $people = $record->personas()->get()->toArray();
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['model' => 'deepseek-flash', 'choices' => [[
            'finish_reason' => 'stop', 'message' => ['content' => json_encode(['resultados' => [[
                'id' => $record->id, 'decision' => 'unrelated', 'evidencia' => 'partido amistoso',
                'motivo' => 'El título trata de deportes.',
            ]]], JSON_THROW_ON_ERROR)],
        ]]])]);
        $result = app(DeepSeekTitleClient::class)->classify($record->id, $record->titulo);
        $record->update($result->toPersistenceAttributes());
        $record->refresh();
        $this->assertSame('unrelated', $record->deepseek_title_status);
        $this->assertSame('partido amistoso', $record->deepseek_title_evidence);
        $this->assertNotNull($record->deepseek_title_attempted_at);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $record->deepseek_title_attempted_at);
        $this->assertNull($record->deepseek_bypass_at);
        foreach ($before as $key => $value) {
            if (! in_array($key, $this->columns(), true)) {
                $this->assertSame($value, $record->getRawOriginal($key), $key);
            }
        }
        $this->assertSame($people, $record->personas()->get()->toArray());
        $record->update(['deepseek_bypass_at' => now()]);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $record->fresh()->deepseek_bypass_at);
        Http::assertSentCount(1);
    }

    private function columns(): array
    {
        return [
            'deepseek_title_status', 'deepseek_title_reason', 'deepseek_title_evidence',
            'deepseek_title_error_code', 'deepseek_title_attempted_at', 'deepseek_title_model',
            'deepseek_title_prompt_version', 'deepseek_bypass_at',
        ];
    }
}
