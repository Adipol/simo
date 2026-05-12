<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Services\Gemini\DTOs\FiltroResultadoDTO;
use PHPUnit\Framework\TestCase;

class FiltroResultadoDTOTest extends TestCase
{
    private function validData(): array
    {
        return [
            'personas' => [[
                'nombre' => 'Juan Pérez',
                'cargo' => 'Ministro de Economía',
                'categoria' => 'PEP',
                'entidad_tipo' => null,
                'confianza' => 95,
                'evento' => 'designacion',
                'motivo' => 'Cargo ejecutivo de alto nivel',
            ]],
            'motivo_general' => 'Artículo sobre acción ministerial',
        ];
    }

    public function test_from_array_with_valid_data(): void
    {
        $dto = FiltroResultadoDTO::fromArray($this->validData());

        $this->assertCount(1, $dto->personas);
        $this->assertSame('Juan Pérez', $dto->personas[0]->nombre);
        $this->assertSame('Ministro de Economía', $dto->personas[0]->cargo);
        $this->assertSame('PEP', $dto->personas[0]->categoria);
        $this->assertSame(95, $dto->personas[0]->confianza);
        $this->assertSame('Cargo ejecutivo de alto nivel', $dto->personas[0]->motivo);
        $this->assertSame('Artículo sobre acción ministerial', $dto->motivoGeneral);
    }

    public function test_from_array_with_null_categoria(): void
    {
        $data = $this->validData();
        $data['personas'][0]['categoria'] = null;

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertNull($dto->personas[0]->categoria);
    }

    public function test_from_array_with_null_nombre_and_cargo(): void
    {
        $data = $this->validData();
        $data['personas'][0]['nombre'] = '';
        $data['personas'][0]['cargo'] = null;
        $data['personas'][0]['categoria'] = null;

        $dto = FiltroResultadoDTO::fromArray($data);

        // Personas with empty nombre are filtered out
        $this->assertCount(0, $dto->personas);
    }

    public function test_from_array_casts_confianza_to_int(): void
    {
        $data = $this->validData();
        $data['personas'][0]['confianza'] = '85';

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertSame(85, $dto->personas[0]->confianza);
    }

    public function test_from_array_with_opi_categoria(): void
    {
        $data = $this->validData();
        $data['personas'][0]['categoria'] = 'OPI';
        $data['personas'][0]['nombre'] = 'Rodrigo Vargas';
        $data['personas'][0]['cargo'] = null;

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertSame('OPI', $dto->personas[0]->categoria);
    }

    public function test_from_array_throws_when_personas_key_missing(): void
    {
        $data = $this->validData();
        unset($data['personas']);

        $this->expectException(GeminiInvalidResponseException::class);
        FiltroResultadoDTO::fromArray($data);
    }

    public function test_from_array_with_empty_personas_array(): void
    {
        $data = $this->validData();
        $data['personas'] = [];

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertCount(0, $dto->personas);
        $this->assertFalse($dto->hasPersonas());
    }

    public function test_from_array_with_motivo_general(): void
    {
        $data = $this->validData();
        $data['motivo_general'] = 'Texto deportivo sin mención de cargos públicos';

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertSame('Texto deportivo sin mención de cargos públicos', $dto->motivoGeneral);
    }

    public function test_dto_is_readonly(): void
    {
        $dto = FiltroResultadoDTO::fromArray($this->validData());

        $ref = new \ReflectionClass($dto);
        $this->assertTrue($ref->isReadOnly());
    }

    // ─── entidadTipo field ───────────────────────────────────────────────────

    public function test_from_array_parses_entidad_tipo_field(): void
    {
        $data = $this->validData();
        $data['personas'][0]['entidad_tipo'] = 'publica';

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertSame('publica', $dto->personas[0]->entidadTipo);
    }

    public function test_from_array_entidad_tipo_defaults_to_null_when_missing(): void
    {
        $data = $this->validData();
        unset($data['personas'][0]['entidad_tipo']);

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertNull($dto->personas[0]->entidadTipo);
    }

    public function test_from_array_entidad_tipo_accepts_all_valid_values(): void
    {
        foreach (['publica', 'privada', 'desconocido'] as $value) {
            $data = $this->validData();
            $data['personas'][0]['entidad_tipo'] = $value;
            $dto = FiltroResultadoDTO::fromArray($data);
            $this->assertSame($value, $dto->personas[0]->entidadTipo);
        }
    }
}
