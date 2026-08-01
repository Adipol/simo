<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Cambio;
use App\Models\Fuente;
use App\Models\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StructuredAuthoritiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_asuss_source_is_configured_by_url_and_structured_json_is_cast(): void
    {
        $asuss = Fuente::factory()->create([
            'url' => 'https://www.asuss.gob.bo/recursos-humanos/#autoridades',
            'autoridades_extractor' => 'divi_blurb',
        ]);
        $other = Fuente::factory()->create(['autoridades_extractor' => null]);

        $this->assertSame('divi_blurb', $asuss->fresh()->autoridades_extractor);
        $this->assertNull($other->fresh()->autoridades_extractor);

        $snapshot = Snapshot::create([
            'fuente_id' => $asuss->id,
            'hash' => str_repeat('a', 64),
            'texto' => 'Autoridades',
            'autoridades_json' => [['cargo' => 'Director', 'persona' => 'Ana Pérez']],
        ]);
        $cambio = Cambio::factory()->create([
            'fuente_id' => $asuss->id,
            'autoridades_eventos_json' => [
                'version' => 1,
                'events' => [[
                    'type' => 'reemplazo',
                    'old' => ['cargo' => 'Director', 'persona' => 'Ana Pérez'],
                    'new' => ['cargo' => 'Director', 'persona' => 'María Quispe'],
                ]],
            ],
        ]);

        $this->assertSame('Ana Pérez', $snapshot->fresh()->autoridades_json[0]['persona']);
        $this->assertSame('reemplazo', $cambio->fresh()->autoridades_eventos_json['events'][0]['type']);
    }
}
