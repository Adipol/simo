<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ProcessAuthorityReviewAnalysis;
use App\Models\AuthorityRemovalReview;
use App\Models\AuthorityReviewAnalysisOutbox;
use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\Snapshot;
use App\Services\Gemini\GeminiAnalisisService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Tests\TestCase;

final class ProcessAuthorityReviewAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_delivery_performs_effective_analysis_once(): void
    {
        config(['services.gemini.enabled' => true]);
        $event = $this->event();

        $this->mock(GeminiAnalisisService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('analizarLote')
                ->once()
                ->withArgs(function (Collection $records): bool {
                    $records->first()->update([
                        'gemini_analyzed' => true,
                        'gemini_analyzed_at' => now(),
                    ]);

                    return true;
                });
        });

        $job = new ProcessAuthorityReviewAnalysis($event->id, $event->idempotency_key);
        $job->handle(app(GeminiAnalisisService::class));
        $job->handle(app(GeminiAnalisisService::class));

        $this->assertNotNull($event->fresh()->processed_at);
    }

    private function event(): AuthorityReviewAnalysisOutbox
    {
        $fuente = Fuente::factory()->create();
        $snapshot = Snapshot::create([
            'fuente_id' => $fuente->id,
            'hash' => str_repeat('a', 64),
            'texto' => 'content',
            'metodo' => 'html_estatico',
            'fecha' => now(),
        ]);
        $cambio = Cambio::withoutEvents(static fn (): Cambio => Cambio::create([
            'fuente_id' => $fuente->id,
            'hash_anterior' => str_repeat('a', 64),
            'hash_nuevo' => str_repeat('b', 64),
            'diff_texto' => 'authority change',
        ]));
        $review = AuthorityRemovalReview::create([
            'fuente_id' => $fuente->id,
            'snapshot_base_id' => $snapshot->id,
            'origen' => 'pep_monitor',
            'linea_base_json' => [],
            'candidato_json' => [],
            'eventos_propuestos_json' => [],
            'evidencia_json' => ['version' => 1],
            'fingerprint' => str_repeat('c', 64),
            'estado' => 'confirmed',
            'cambio_confirmado_id' => $cambio->id,
        ]);

        return AuthorityReviewAnalysisOutbox::create([
            'idempotency_key' => "authority-removal-review:{$review->id}:cambio:{$cambio->id}",
            'authority_removal_review_id' => $review->id,
            'cambio_id' => $cambio->id,
        ]);
    }
}
