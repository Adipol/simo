<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard\DTOs;

use App\Services\Dashboard\DTOs\CambioSummary;
use App\Services\Dashboard\DTOs\PepHighConfidence;
use App\Services\Dashboard\DTOs\RecentDiscoveriesDTO;
use Tests\TestCase;

class RecentDiscoveriesDTOTest extends TestCase
{
    private function makeCambioSummary(int $id = 1): CambioSummary
    {
        return new CambioSummary(
            id: $id,
            fuente_nombre: 'Fuente ' . $id,
            riesgo: 'alto',
            lineas_nuevas: 3,
            lineas_quitadas: 1,
            analisis_snippet: 'Snippet de análisis',
            fecha: new \DateTimeImmutable('2026-05-01'),
        );
    }

    private function makePepHighConfidence(int $id = 1): PepHighConfidence
    {
        return new PepHighConfidence(
            id: $id,
            nombre: 'Persona ' . $id,
            cargo: 'Director',
            pais: 'BO',
            confianza: 0.95,
            categoria: 'pep',
            fecha: new \DateTimeImmutable('2026-05-01'),
        );
    }

    // ─── CambioSummary ───────────────────────────────────────────────────────

    public function test_cambio_summary_stores_all_fields(): void
    {
        $cambio = new CambioSummary(
            id: 10,
            fuente_nombre: 'Gobierno Bolivia',
            riesgo: 'alto',
            lineas_nuevas: 5,
            lineas_quitadas: 2,
            analisis_snippet: 'Cambio relevante detectado',
            fecha: new \DateTimeImmutable('2026-04-30'),
        );

        $this->assertSame(10, $cambio->id);
        $this->assertSame('Gobierno Bolivia', $cambio->fuente_nombre);
        $this->assertSame('alto', $cambio->riesgo);
        $this->assertSame(5, $cambio->lineas_nuevas);
        $this->assertSame(2, $cambio->lineas_quitadas);
        $this->assertSame('Cambio relevante detectado', $cambio->analisis_snippet);
        $this->assertInstanceOf(\DateTimeImmutable::class, $cambio->fecha);
    }

    public function test_cambio_summary_analisis_snippet_can_be_null(): void
    {
        $cambio = new CambioSummary(
            id: 2,
            fuente_nombre: 'Fuente Y',
            riesgo: 'bajo',
            lineas_nuevas: 0,
            lineas_quitadas: 0,
            analisis_snippet: null,
            fecha: new \DateTimeImmutable('2026-05-01'),
        );

        $this->assertNull($cambio->analisis_snippet);
    }

    // ─── PepHighConfidence ───────────────────────────────────────────────────

    public function test_pep_high_confidence_stores_all_fields(): void
    {
        $pep = new PepHighConfidence(
            id: 5,
            nombre: 'Juan García',
            cargo: 'Presidente',
            pais: 'AR',
            confianza: 0.92,
            categoria: 'pep',
            fecha: new \DateTimeImmutable('2026-05-01 10:00:00'),
        );

        $this->assertSame(5, $pep->id);
        $this->assertSame('Juan García', $pep->nombre);
        $this->assertSame('Presidente', $pep->cargo);
        $this->assertSame('AR', $pep->pais);
        $this->assertEqualsWithDelta(0.92, $pep->confianza, 0.001);
        $this->assertSame('pep', $pep->categoria);
        $this->assertInstanceOf(\DateTimeImmutable::class, $pep->fecha);
    }

    public function test_pep_high_confidence_cargo_and_pais_can_be_null(): void
    {
        $pep = new PepHighConfidence(
            id: 6,
            nombre: 'María López',
            cargo: null,
            pais: null,
            confianza: 0.88,
            categoria: 'opi',
            fecha: new \DateTimeImmutable('2026-05-01'),
        );

        $this->assertNull($pep->cargo);
        $this->assertNull($pep->pais);
    }

    // ─── RecentDiscoveriesDTO ────────────────────────────────────────────────

    public function test_recent_discoveries_stores_top_peps_and_cambios(): void
    {
        $peps = [
            $this->makePepHighConfidence(1),
            $this->makePepHighConfidence(2),
            $this->makePepHighConfidence(3),
        ];

        $cambios = [
            $this->makeCambioSummary(10),
            $this->makeCambioSummary(11),
        ];

        $dto = new RecentDiscoveriesDTO(
            top_peps: $peps,
            top_cambios: $cambios,
        );

        $this->assertCount(3, $dto->top_peps);
        $this->assertCount(2, $dto->top_cambios);
        $this->assertInstanceOf(PepHighConfidence::class, $dto->top_peps[0]);
        $this->assertInstanceOf(CambioSummary::class, $dto->top_cambios[0]);
    }

    public function test_recent_discoveries_empty_arrays_are_valid(): void
    {
        $dto = new RecentDiscoveriesDTO(
            top_peps: [],
            top_cambios: [],
        );

        $this->assertCount(0, $dto->top_peps);
        $this->assertCount(0, $dto->top_cambios);
    }

    public function test_recent_discoveries_max_5_peps(): void
    {
        // Build 5 PEPs — the contract is top 5
        $peps = array_map(
            fn (int $i) => $this->makePepHighConfidence($i),
            range(1, 5)
        );

        $dto = new RecentDiscoveriesDTO(top_peps: $peps, top_cambios: []);

        $this->assertCount(5, $dto->top_peps);
    }

    public function test_recent_discoveries_max_5_cambios(): void
    {
        $cambios = array_map(
            fn (int $i) => $this->makeCambioSummary($i),
            range(1, 5)
        );

        $dto = new RecentDiscoveriesDTO(top_peps: [], top_cambios: $cambios);

        $this->assertCount(5, $dto->top_cambios);
    }
}
