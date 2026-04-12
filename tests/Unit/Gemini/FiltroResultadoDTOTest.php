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
            'is_pep' => true,
            'nombre' => 'Juan Pérez',
            'cargo' => 'Ministro de Economía',
            'categoria' => 'PEP',
            'confianza' => 95,
            'motivo' => 'Cargo ejecutivo de alto nivel',
        ];
    }

    public function test_from_array_with_valid_data(): void
    {
        $dto = FiltroResultadoDTO::fromArray($this->validData());

        $this->assertTrue($dto->isPep);
        $this->assertSame('Juan Pérez', $dto->nombre);
        $this->assertSame('Ministro de Economía', $dto->cargo);
        $this->assertSame('PEP', $dto->categoria);
        $this->assertSame(95, $dto->confianza);
        $this->assertSame('Cargo ejecutivo de alto nivel', $dto->motivo);
    }

    public function test_from_array_with_null_categoria(): void
    {
        $data = $this->validData();
        $data['categoria'] = null;
        $data['is_pep'] = false;

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertFalse($dto->isPep);
        $this->assertNull($dto->categoria);
    }

    public function test_from_array_with_null_nombre_and_cargo(): void
    {
        $data = $this->validData();
        $data['nombre'] = null;
        $data['cargo'] = null;
        $data['is_pep'] = false;
        $data['categoria'] = null;

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertNull($dto->nombre);
        $this->assertNull($dto->cargo);
    }

    public function test_from_array_casts_confianza_to_int(): void
    {
        $data = $this->validData();
        $data['confianza'] = '85';

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertSame(85, $dto->confianza);
    }

    public function test_from_array_with_opi_categoria(): void
    {
        $data = $this->validData();
        $data['categoria'] = 'OPI';
        $data['nombre'] = 'Rodrigo Vargas';
        $data['cargo'] = null;

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertSame('OPI', $dto->categoria);
    }

    public function test_from_array_throws_when_is_pep_missing(): void
    {
        $data = $this->validData();
        unset($data['is_pep']);

        $this->expectException(GeminiInvalidResponseException::class);
        FiltroResultadoDTO::fromArray($data);
    }

    public function test_from_array_throws_when_confianza_missing(): void
    {
        $data = $this->validData();
        unset($data['confianza']);

        $this->expectException(GeminiInvalidResponseException::class);
        FiltroResultadoDTO::fromArray($data);
    }

    public function test_from_array_throws_when_motivo_missing(): void
    {
        $data = $this->validData();
        unset($data['motivo']);

        $this->expectException(GeminiInvalidResponseException::class);
        FiltroResultadoDTO::fromArray($data);
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
        $data = array_merge($this->validData(), ['entidad_tipo' => 'publica']);

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertSame('publica', $dto->entidadTipo);
    }

    public function test_from_array_entidad_tipo_defaults_to_null_when_missing(): void
    {
        $data = $this->validData(); // no entidad_tipo key

        $dto = FiltroResultadoDTO::fromArray($data);

        $this->assertNull($dto->entidadTipo);
    }

    public function test_from_array_entidad_tipo_accepts_all_valid_values(): void
    {
        foreach (['publica', 'privada', 'desconocido'] as $value) {
            $data = array_merge($this->validData(), ['entidad_tipo' => $value]);
            $dto = FiltroResultadoDTO::fromArray($data);
            $this->assertSame($value, $dto->entidadTipo);
        }
    }
}
