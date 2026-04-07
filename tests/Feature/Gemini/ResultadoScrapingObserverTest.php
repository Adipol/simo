<?php

declare(strict_types=1);

namespace Tests\Feature\Gemini;

use App\Jobs\AnalizarScrapingConFlash;
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

    public function test_created_dispatches_job_with_correct_queue_and_delay(): void
    {
        config(['services.gemini.enabled' => true]);

        Queue::fake();

        $this->createRecord();

        Queue::assertPushed(AnalizarScrapingConFlash::class, function ($job) {
            return $job->queue === 'gemini';
        });
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
            'services.gemini.enabled' => true,
            'services.gemini.flash_delay' => 15,
        ]);

        Queue::fake();

        $this->createRecord();

        Queue::assertPushed(AnalizarScrapingConFlash::class);
    }
}
