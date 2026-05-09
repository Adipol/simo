<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Exceptions\Gemini\GeminiInvalidResponseException;
use App\Services\Gemini\DTOs\AnalisisCambioDTO;
use PHPUnit\Framework\TestCase;

class AnalisisCambioDTOTest extends TestCase
{
    private function validData(): array
    {
        return [
            'persona_removida' => 'Carlos López',
            'persona_nueva' => 'Ana García',
            'cargo' => 'Ministro de Hacienda',
            'es_mae' => true,
            'riesgo' => 'alto',
            'analisis' => 'Cambio de MAE en cartera sensible de finanzas públicas',
        ];
    }

    public function test_from_array_with_valid_data(): void
    {
        $dto = AnalisisCambioDTO::fromArray($this->validData());

        $this->assertSame('Carlos López', $dto->personaRemovida);
        $this->assertSame('Ana García', $dto->personaNueva);
        $this->assertSame('Ministro de Hacienda', $dto->cargo);
        $this->assertTrue($dto->esMae);
        $this->assertSame('alto', $dto->riesgo);
        $this->assertSame('Cambio de MAE en cartera sensible de finanzas públicas', $dto->analisis);
    }

    public function test_from_array_with_null_personas(): void
    {
        $data = $this->validData();
        $data['persona_removida'] = null;
        $data['persona_nueva'] = null;
        $data['cargo'] = null;
        $data['es_mae'] = false;
        $data['riesgo'] = 'bajo';

        $dto = AnalisisCambioDTO::fromArray($data);

        $this->assertNull($dto->personaRemovida);
        $this->assertNull($dto->personaNueva);
        $this->assertNull($dto->cargo);
        $this->assertFalse($dto->esMae);
    }

    public function test_from_array_accepts_riesgo_alto(): void
    {
        $data = $this->validData();
        $data['riesgo'] = 'alto';

        $dto = AnalisisCambioDTO::fromArray($data);
        $this->assertSame('alto', $dto->riesgo);
    }

    public function test_from_array_accepts_riesgo_medio(): void
    {
        $data = $this->validData();
        $data['riesgo'] = 'medio';

        $dto = AnalisisCambioDTO::fromArray($data);
        $this->assertSame('medio', $dto->riesgo);
    }

    public function test_from_array_accepts_riesgo_bajo(): void
    {
        $data = $this->validData();
        $data['riesgo'] = 'bajo';

        $dto = AnalisisCambioDTO::fromArray($data);
        $this->assertSame('bajo', $dto->riesgo);
    }

    public function test_from_array_throws_on_invalid_riesgo(): void
    {
        $data = $this->validData();
        $data['riesgo'] = 'critico';

        $this->expectException(GeminiInvalidResponseException::class);
        AnalisisCambioDTO::fromArray($data);
    }

    public function test_from_array_throws_when_es_mae_missing(): void
    {
        $data = $this->validData();
        unset($data['es_mae']);

        $this->expectException(GeminiInvalidResponseException::class);
        AnalisisCambioDTO::fromArray($data);
    }

    public function test_from_array_throws_when_riesgo_missing(): void
    {
        $data = $this->validData();
        unset($data['riesgo']);

        $this->expectException(GeminiInvalidResponseException::class);
        AnalisisCambioDTO::fromArray($data);
    }

    public function test_from_array_throws_when_analisis_missing(): void
    {
        $data = $this->validData();
        unset($data['analisis']);

        $this->expectException(GeminiInvalidResponseException::class);
        AnalisisCambioDTO::fromArray($data);
    }

    public function test_dto_is_readonly(): void
    {
        $dto = AnalisisCambioDTO::fromArray($this->validData());

        $ref = new \ReflectionClass($dto);
        $this->assertTrue($ref->isReadOnly());
    }

    public function test_from_array_parses_personas_detectadas(): void
    {
        $data = $this->validData();
        $data['personas_detectadas'] = [
            ['nombre' => 'Davalos Yoshida Adolfo Arturo', 'cargo' => 'Director Ejecutivo'],
            ['nombre' => 'Camacho Garcia Lorna Alcira', 'cargo' => 'Director Administrativo Financiero'],
        ];

        $dto = AnalisisCambioDTO::fromArray($data);

        $this->assertCount(2, $dto->personasDetectadas);
        $this->assertSame('Davalos Yoshida Adolfo Arturo', $dto->personasDetectadas[0]['nombre']);
        $this->assertSame('Director Ejecutivo', $dto->personasDetectadas[0]['cargo']);
    }

    public function test_from_array_defaults_personas_detectadas_to_empty_array(): void
    {
        $data = $this->validData();
        // No personas_detectadas en la respuesta — Gemini puede omitirlo
        $dto = AnalisisCambioDTO::fromArray($data);

        $this->assertSame([], $dto->personasDetectadas);
    }

    public function test_sin_novedad_includes_empty_personas_detectadas(): void
    {
        $dto = AnalisisCambioDTO::sinNovedad('Sin diff ni imágenes');

        $this->assertSame([], $dto->personasDetectadas);
        $this->assertNull($dto->personaNueva);
        $this->assertNull($dto->personaRemovida);
        $this->assertSame('bajo', $dto->riesgo);
    }
}
