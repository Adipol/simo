<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Models\ResultadoScraping;
use App\Services\Scraper\TitleGateHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TitleGateHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.deepseek_title_gate' => ['enabled' => true, 'api_key' => 'never-expose-this-key',
            'model' => 'deepseek-flash', 'activation_after_id' => 0, 'timeout' => 20, 'batch_size' => 1]]);
    }

    private function attempt(string $status, string $time): ResultadoScraping
    {
        return ResultadoScraping::withoutEvents(fn (): ResultadoScraping => ResultadoScraping::factory()->create([
            'deepseek_title_status' => $status, 'deepseek_title_attempted_at' => $time,
            'deepseek_title_error_code' => 'untrusted secret error',
        ]));
    }

    public function test_configuration_without_evidence_is_not_verified_health_and_never_exposes_secrets(): void
    {
        $health = app(TitleGateHealth::class)->status();
        $this->assertTrue($health['configured']);
        $this->assertSame('ready', $health['configuration']);
        $this->assertSame('unknown', $health['last_status']);
        $this->assertSame('Configurada; aún sin llamadas verificadas', $health['label']);
        $this->assertNull($health['last_attempt_at']);
        $this->assertNull($health['last_success_at']);
        $this->assertStringNotContainsString('never-expose', json_encode($health, JSON_THROW_ON_ERROR));
        config(['services.deepseek_title_gate.enabled' => false]);
        $this->assertSame('disabled', app(TitleGateHealth::class)->status()['configuration']);
        config(['services.deepseek_title_gate.enabled' => true, 'services.deepseek_title_gate.api_key' => '']);
        $this->assertSame('not_configured', app(TitleGateHealth::class)->status()['configuration']);
        Http::assertNothingSent();
    }

    public function test_latest_attempt_not_historical_success_controls_health_with_id_tiebreak(): void
    {
        $this->attempt('relevant', '2026-10-01 12:00:00');
        foreach (['doubtful', 'unrelated', 'invalid_response', 'technical_error'] as $status) {
            $this->attempt($status, '2026-10-02 12:00:00');
            $health = app(TitleGateHealth::class)->status();
            $this->assertSame($status, $health['last_status']);
            $this->assertSame('2026-10-02 12:00:00', $health['last_attempt_at']);
            $this->assertSame(in_array($status, ['doubtful', 'unrelated'], true)
                ? 'Última llamada validada' : 'Último intento fallido', $health['label']);
            $this->assertStringNotContainsString('untrusted', json_encode($health, JSON_THROW_ON_ERROR));
        }
        // A bypass is not a new provider attempt, even when its ID is newer.
        ResultadoScraping::withoutEvents(fn () => ResultadoScraping::factory()->create([
            'deepseek_bypass_at' => now(), 'deepseek_title_status' => null, 'deepseek_title_attempted_at' => null,
        ]));
        $this->assertSame('technical_error', app(TitleGateHealth::class)->status()['last_status']);
        $this->assertSame('2026-10-02 12:00:00', app(TitleGateHealth::class)->status()['last_success_at']);
        config(['services.deepseek_title_gate.enabled' => false]);
        $this->assertSame('technical_error', app(TitleGateHealth::class)->status()['last_status']);
        Http::assertNothingSent();
    }
}
