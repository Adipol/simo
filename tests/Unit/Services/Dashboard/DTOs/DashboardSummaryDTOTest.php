<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\BacklogAgeDTO;
use App\Services\Dashboard\DTOs\CambioSummary;
use App\Services\Dashboard\DTOs\DashboardSummaryDTO;
use App\Services\Dashboard\DTOs\HeroCardDTO;
use App\Services\Dashboard\DTOs\PepHighConfidence;
use App\Services\Dashboard\DTOs\RecentDiscoveriesDTO;
use App\Services\Dashboard\DTOs\TriageStripDTO;
use Tests\TestCase;

class DashboardSummaryDTOTest extends TestCase
{
    private function makeHeroCard(): HeroCardDTO
    {
        return HeroCardDTO::fromArray([
            'id'             => 1,
            'fuente_nombre'  => 'Gobierno Bolivia',
            'riesgo'         => 'alto',
            'es_mae'         => true,
            'dias_pendiente' => 4,
            'score'          => 9.33,
            'accion_url'     => '/pep/cambios?cambio=1',
            'fecha'          => '2026-05-01 00:00:00',
        ]);
    }

    private function makeTriage(): TriageStripDTO
    {
        $zeros = [0, 0, 0, 0, 0, 0, 0];

        return new TriageStripDTO(
            pendientes_alto: 2,
            pendientes_medio: 3,
            pendientes_bajo: 1,
            sin_leer: 5,
            sparkline_alto: $zeros,
            sparkline_medio: $zeros,
            sparkline_bajo: $zeros,
            sparkline_sin_leer: $zeros,
        );
    }

    private function makeBacklog(): BacklogAgeDTO
    {
        return new BacklogAgeDTO(
            pendientes_antiguos: 2,
            dias_threshold: 3,
            mas_antiguo_dias: 7,
        );
    }

    private function makeDiscoveries(): RecentDiscoveriesDTO
    {
        return new RecentDiscoveriesDTO(top_peps: [], top_cambios: []);
    }

    // ─── Full composite assembly ─────────────────────────────────────────────

    public function test_composite_assembles_all_child_dtos(): void
    {
        $hero       = $this->makeHeroCard();
        $triage     = $this->makeTriage();
        $backlog    = $this->makeBacklog();
        $discoveries = $this->makeDiscoveries();
        $ultimaActividad = new \DateTimeImmutable('2026-05-02 08:00:00');

        $dto = new DashboardSummaryDTO(
            hero: $hero,
            triage: $triage,
            backlog: $backlog,
            discoveries: $discoveries,
            ultima_actividad_revisada: $ultimaActividad,
        );

        $this->assertSame($hero, $dto->hero);
        $this->assertSame($triage, $dto->triage);
        $this->assertSame($backlog, $dto->backlog);
        $this->assertSame($discoveries, $dto->discoveries);
        $this->assertInstanceOf(\DateTimeImmutable::class, $dto->ultima_actividad_revisada);
    }

    public function test_hero_can_be_null_when_no_pending_cambios(): void
    {
        $dto = new DashboardSummaryDTO(
            hero: null,
            triage: $this->makeTriage(),
            backlog: $this->makeBacklog(),
            discoveries: $this->makeDiscoveries(),
            ultima_actividad_revisada: null,
        );

        $this->assertNull($dto->hero);
        $this->assertNull($dto->ultima_actividad_revisada);
    }

    public function test_ultima_actividad_revisada_can_be_null(): void
    {
        $dto = new DashboardSummaryDTO(
            hero: null,
            triage: $this->makeTriage(),
            backlog: $this->makeBacklog(),
            discoveries: $this->makeDiscoveries(),
            ultima_actividad_revisada: null,
        );

        $this->assertNull($dto->ultima_actividad_revisada);
    }
}
