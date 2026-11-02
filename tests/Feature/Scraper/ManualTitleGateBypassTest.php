<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Jobs\AnalizarScrapingConFlash;
use App\Models\ResultadoScraping;
use App\Models\User;
use App\Services\Scraper\ManualTitleGateBypass;
use App\Services\Scraper\ScraperTitleGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualTitleGateBypassTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.gemini.enabled' => true, 'services.gemini.api_key' => 'fake']);
        $this->actingAs(User::factory()->create());
        Gate::define('gestionar resultados', static fn (User $user): bool => true);
    }

    private function held(array $attributes = []): ResultadoScraping
    {
        return ResultadoScraping::withoutEvents(fn (): ResultadoScraping => ResultadoScraping::factory()->create(array_merge([
            'gemini_analyzed' => false, 'gemini_analyzed_at' => null,
            'deepseek_title_status' => 'unrelated', 'deepseek_bypass_at' => null,
            'deepseek_title_reason' => 'Deportes', 'deepseek_title_evidence' => 'partido',
            'deepseek_title_attempted_at' => now()->subMinute(), 'leido' => true,
            'relevante' => true, 'descartado' => true, 'archivado_at' => now(),
            'notas' => 'Conservar', 'gemini_motivo' => 'No borrar',
        ], $attributes)))->refresh();
    }

    public function test_persists_only_bypass_then_schedules_once_and_preserves_personas(): void
    {
        $record = $this->held();
        $record->personas()->create(['nombre' => 'Persona conservada', 'categoria' => 'PEP', 'confianza' => 80, 'threshold_passed' => true]);
        $before = $record->refresh()->getAttributes();
        $personas = $record->personas()->get()->toArray();
        $service = app(ManualTitleGateBypass::class);
        $this->assertSame('scheduled', $service->send($record->id));
        $record->refresh();
        $this->assertNotNull($record->deepseek_bypass_at);
        unset($before['deepseek_bypass_at']);
        $after = $record->getAttributes();
        unset($after['deepseek_bypass_at']);
        $this->assertSame($before, $after);
        $this->assertSame($personas, $record->personas()->get()->toArray());
        $this->assertSame('ineligible', $service->send($record->id));
        Queue::assertPushed(AnalizarScrapingConFlash::class, 1);
        Queue::assertPushed(AnalizarScrapingConFlash::class, fn ($job): bool => $job->queue === 'gemini');
        Http::assertNothingSent();
    }

    public function test_busy_shared_lock_never_mutates_or_dispatches(): void
    {
        $record = $this->held();
        $before = $record->getAttributes();
        $lock = Cache::lock(ScraperTitleGate::lockKey($record->id), 360);
        $this->assertTrue($lock->get());
        try {
            $this->assertSame('busy', app(ManualTitleGateBypass::class)->send($record->id));
            $this->assertSame($before, $record->refresh()->getAttributes());
        } finally {
            $lock->release();
        }
        Queue::assertNothingPushed();
    }

    public function test_rechecks_fresh_database_state_and_rejects_all_ineligible_states(): void
    {
        foreach ([['gemini_analyzed' => true], ['gemini_analyzed_at' => now()],
            ['deepseek_bypass_at' => now()], ['deepseek_title_status' => null],
            ['deepseek_title_status' => 'relevant'], ['deepseek_title_status' => 'technical_error']] as $state) {
            $record = $this->held();
            ResultadoScraping::whereKey($record->id)->update($state);
            $before = $record->refresh()->getAttributes();
            $this->assertSame('ineligible', app(ManualTitleGateBypass::class)->send($record->id));
            $this->assertSame($before, $record->refresh()->getAttributes());
        }
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_disabled_or_missing_gemini_configuration_has_no_side_effects(): void
    {
        $record = $this->held();
        $before = $record->getAttributes();
        foreach (['services.gemini.enabled' => false, 'services.gemini.api_key' => ''] as $setting => $value) {
            config(['services.gemini.enabled' => true, 'services.gemini.api_key' => 'fake', $setting => $value]);
            $this->assertSame('unavailable', app(ManualTitleGateBypass::class)->send($record->id));
            $this->assertSame($before, $record->refresh()->getAttributes());
        }
        Queue::assertNothingPushed();
    }

    public function test_dispatch_failure_keeps_bypass_for_scanner_rescue_without_claiming_success(): void
    {
        $record = $this->held();
        Bus::shouldReceive('dispatch')->once()->andReturnUsing(function (AnalizarScrapingConFlash $job) use ($record): never {
            $this->assertNotNull($record->refresh()->deepseek_bypass_at);
            throw new \RuntimeException('sensitive provider detail');
        });
        $this->assertSame('dispatch_failed', app(ManualTitleGateBypass::class)->send($record->id));
        $this->assertTrue(ResultadoScraping::pendienteAnalisisScraper()->whereKey($record->id)->exists());
        $this->assertSame('ineligible', app(ManualTitleGateBypass::class)->send($record->id));
        Http::assertNothingSent();
    }
}
