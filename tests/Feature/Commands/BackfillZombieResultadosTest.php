<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use App\Models\ResultadoScraping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillZombieResultadosTest extends TestCase
{
    use RefreshDatabase;

    private function createRecord(array $overrides = []): ResultadoScraping
    {
        ResultadoScraping::flushEventListeners();

        return ResultadoScraping::create(array_merge([
            'url' => 'https://example.com/article-'.uniqid(),
            'keyword' => 'corrupcion',
            'pais' => 'BO',
            'categoria' => 'politica',
            'titulo' => 'Test Article',
            'contexto' => 'El ministro presidió la sesión',
            'relevance_score' => 80,
            'gemini_analyzed' => false,
        ], $overrides));
    }

    public function test_backfill_zombies_dry_run_does_not_modify_rows(): void
    {
        // Arrange: create zombie rows (analyzed=true, is_pep=null)
        $zombie1 = $this->createRecord(['gemini_analyzed' => true, 'gemini_is_pep' => null]);
        $zombie2 = $this->createRecord(['gemini_analyzed' => true, 'gemini_is_pep' => null]);

        // Act: run with --dry-run
        $this->artisan('resultados:backfill-zombies', ['--dry-run' => true])
            ->assertSuccessful();

        // Assert: rows are NOT modified
        $zombie1->refresh();
        $zombie2->refresh();

        $this->assertNull($zombie1->gemini_is_pep, 'Dry run must not modify zombie rows');
        $this->assertNull($zombie2->gemini_is_pep, 'Dry run must not modify zombie rows');
    }

    public function test_backfill_zombies_updates_zombies_to_false(): void
    {
        // Arrange: create zombie rows
        $zombie1 = $this->createRecord(['gemini_analyzed' => true, 'gemini_is_pep' => null]);
        $zombie2 = $this->createRecord(['gemini_analyzed' => true, 'gemini_is_pep' => null]);

        // Act: run without --dry-run
        $this->artisan('resultados:backfill-zombies')
            ->assertSuccessful();

        // Assert: zombie rows are now gemini_is_pep=false
        $zombie1->refresh();
        $zombie2->refresh();

        $this->assertFalse($zombie1->gemini_is_pep, 'Zombie rows must be updated to gemini_is_pep=false');
        $this->assertFalse($zombie2->gemini_is_pep, 'Zombie rows must be updated to gemini_is_pep=false');
    }

    public function test_backfill_zombies_does_not_touch_already_classified_rows(): void
    {
        // Arrange: mix of zombies and already-classified rows
        $zombie = $this->createRecord(['gemini_analyzed' => true, 'gemini_is_pep' => null]);
        $alreadyPep = $this->createRecord(['gemini_analyzed' => true, 'gemini_is_pep' => true]);
        $alreadyNotPep = $this->createRecord(['gemini_analyzed' => true, 'gemini_is_pep' => false]);
        $pending = $this->createRecord(['gemini_analyzed' => false, 'gemini_is_pep' => null]);

        // Act
        $this->artisan('resultados:backfill-zombies')
            ->assertSuccessful();

        // Assert: zombie is fixed, others are untouched
        $zombie->refresh();
        $alreadyPep->refresh();
        $alreadyNotPep->refresh();
        $pending->refresh();

        $this->assertFalse($zombie->gemini_is_pep, 'Zombie must be updated to false');
        $this->assertTrue($alreadyPep->gemini_is_pep, 'Already-classified PEP must not be changed');
        $this->assertFalse($alreadyNotPep->gemini_is_pep, 'Already-classified non-PEP must not be changed');
        $this->assertNull($pending->gemini_is_pep, 'Pending (not-yet-analyzed) must not be touched');
    }
}
