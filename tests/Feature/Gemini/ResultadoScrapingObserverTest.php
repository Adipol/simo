<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Jobs\AnalizarScrapingConFlash;
use App\Jobs\DedupeArticulosJob;
use App\Models\ConfigScript;
use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ResultadoScrapingObserverTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article',
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Ministro de Economía',
            'contexto' => 'El ministro de Economía Juan Pérez firmó un decreto.',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_created_dispatches_only_dedupe_when_dedupe_is_enabled(): void
    {
        config([
            'services.dedupe.enabled' => true,
            'services.gemini.enabled' => true,
        ]);

        Queue::fake();

        $this->createRecord();

        Queue::assertPushedOn('dedupe', DedupeArticulosJob::class);
        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
    }

    public function test_created_dispatches_gemini_directly_when_dedupe_is_disabled(): void
    {
        config([
            'services.dedupe.enabled' => false,
            'services.gemini.enabled' => true,
        ]);

        Queue::fake();

        $this->createRecord();

        Queue::assertNotPushed(DedupeArticulosJob::class);
        Queue::assertPushedOn('gemini', AnalizarScrapingConFlash::class);
    }

    public function test_created_dispatches_gemini_directly_when_database_kill_switch_is_disabled(): void
    {
        config([
            'services.dedupe.enabled' => true,
            'services.gemini.enabled' => true,
        ]);
        ConfigScript::dedupe()->update(['habilitado' => false]);

        Queue::fake();

        $this->createRecord();

        Queue::assertNotPushed(DedupeArticulosJob::class);
        Queue::assertPushedOn('gemini', AnalizarScrapingConFlash::class);
    }

    public function test_gemini_disabled_does_not_dispatch(): void
    {
        config(['services.gemini.enabled' => false]);

        Queue::fake();

        $this->createRecord();

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
    }

    public function test_update_does_not_dispatch(): void
    {
        config(['services.gemini.enabled' => true]);

        Queue::fake();

        $record = $this->createRecord();

        // Reset the fake to clear any dispatches from create
        Queue::fake();

        $record->update(['titulo' => 'Título actualizado']);

        Queue::assertNotPushed(AnalizarScrapingConFlash::class);
    }

    public function test_custom_flash_delay_is_respected(): void
    {
        config([
            'services.dedupe.enabled' => false,
            'services.gemini.enabled' => true,
            'services.gemini.flash_delay' => 15,
        ]);

        Queue::fake();

        $this->createRecord();

        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }
}
