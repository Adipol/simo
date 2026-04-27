<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pep\DTOs;

use App\Services\Pep\DTOs\EventoPepDTO;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * Unit tests for EventoPepDTO.
 *
 * Covers:
 *   - readonly enforcement: mutating a property raises Error
 *   - key() stability: same fields produce the same hash
 *   - key() differentiation: different field values produce different hashes
 *   - key() with null evento: null produces distinct key from empty string
 */
class EventoPepDTOTest extends TestCase
{
    private function makeDto(
        string $nombreNormalizado = 'Juan Pérez',
        ?string $evento = 'renuncia',
        string $categoria = 'PEP',
        ?string $dia = '2026-04-01',
        int $numFuentes = 3,
        ?string $cargo = 'Ministro',
        array $resultadoIds = [1, 2, 3],
        array $sitios = ['El Deber', 'Los Tiempos'],
        ?string $ultimaFecha = '2026-04-01 10:00:00',
        bool $isArchived = false,
    ): EventoPepDTO {
        return new EventoPepDTO(
            nombreNormalizado: $nombreNormalizado,
            evento: $evento,
            categoria: $categoria,
            dia: CarbonImmutable::parse($dia),
            numFuentes: $numFuentes,
            cargo: $cargo,
            resultadoIds: $resultadoIds,
            sitios: $sitios,
            ultimaFechaEncontrado: CarbonImmutable::parse($ultimaFecha),
            isArchived: $isArchived,
        );
    }

    /**
     * Readonly enforcement: attempting to assign to a property must raise Error.
     */
    public function test_dto_is_readonly(): void
    {
        $dto = $this->makeDto();

        $this->expectException(\Error::class);

        // @phpstan-ignore-next-line
        $dto->nombreNormalizado = 'Otro Nombre'; // @phpcs:ignore
    }

    /**
     * key() must be deterministic: same inputs always produce the same hash.
     */
    public function test_dto_key_is_stable(): void
    {
        $dto1 = $this->makeDto(nombreNormalizado: 'Juan Pérez', evento: 'renuncia', dia: '2026-04-01');
        $dto2 = $this->makeDto(nombreNormalizado: 'Juan Pérez', evento: 'renuncia', dia: '2026-04-01');

        $this->assertSame($dto1->key(), $dto2->key(), 'Same (nombre, evento, día) must produce the same key');
        $this->assertNotEmpty($dto1->key(), 'key() must not return an empty string');
    }

    /**
     * key() must differ when any of the triplet fields differ.
     */
    public function test_dto_key_differentiates_groups(): void
    {
        $base     = $this->makeDto(nombreNormalizado: 'Juan Pérez', evento: 'renuncia', dia: '2026-04-01');
        $diffNom  = $this->makeDto(nombreNormalizado: 'Ana García',  evento: 'renuncia', dia: '2026-04-01');
        $diffEv   = $this->makeDto(nombreNormalizado: 'Juan Pérez', evento: 'designacion', dia: '2026-04-01');
        $diffDia  = $this->makeDto(nombreNormalizado: 'Juan Pérez', evento: 'renuncia', dia: '2026-04-02');

        $this->assertNotSame($base->key(), $diffNom->key(), 'Different nombre must produce different keys');
        $this->assertNotSame($base->key(), $diffEv->key(), 'Different evento must produce different keys');
        $this->assertNotSame($base->key(), $diffDia->key(), 'Different day must produce different keys');
    }

    /**
     * key() with null evento must be distinguishable from an empty string evento.
     * Ensures "Sin clasificar" groups don't clash with explicitly empty evento strings.
     */
    public function test_dto_key_null_evento_differs_from_empty_string_evento(): void
    {
        $nullEvento  = $this->makeDto(evento: null);
        $emptyEvento = $this->makeDto(evento: '');

        // Both should be valid keys (not empty)
        $this->assertNotEmpty($nullEvento->key());
        $this->assertNotEmpty($emptyEvento->key());

        // And they should be different from each other
        $this->assertNotSame(
            $nullEvento->key(),
            $emptyEvento->key(),
            'null evento key must differ from empty string evento key'
        );
    }
}
