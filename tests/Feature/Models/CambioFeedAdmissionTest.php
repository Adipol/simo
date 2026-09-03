<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\CambioFeedStatus;
use App\Models\Cambio;
use App\Models\Fuente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CambioFeedAdmissionTest extends TestCase
{
    use RefreshDatabase;

    private Fuente $fuente;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.gemini.enabled' => false]);
        $this->fuente = Fuente::factory()->create();
    }

    public function test_primary_scope_admits_all_four_canonical_types(): void
    {
        $expectedIds = collect([
            ['type' => 'designacion', 'old' => null, 'new' => $this->authority('Ministra', 'Ana Pérez')],
            ['type' => 'remocion', 'old' => $this->authority('Director', 'Luis Soto'), 'new' => null],
            ['type' => 'reemplazo', 'old' => $this->authority('Gerente', 'María Paz'), 'new' => $this->authority('Gerente', 'Juan Solís')],
            ['type' => 'cambio_cargo', 'old' => $this->authority('Asesor', 'Pedro Lima'), 'new' => $this->authority('Viceministro', 'Pedro Lima')],
        ])->map(fn (array $event): int => $this->createCambio([
            'feed_status' => CambioFeedStatus::Primary,
            'autoridades_eventos_json' => ['version' => 1, 'events' => [$event]],
        ])->id)->all();

        $this->assertEqualsCanonicalizing($expectedIds, Cambio::primaryFeed()->pluck('id')->all());
    }

    public function test_primary_scope_rejects_malformed_unknown_proxy_editorial_and_legacy_rows(): void
    {
        $validPayload = $this->payload();
        $review = $this->createCambio([
            'feed_status' => CambioFeedStatus::Review,
            'autoridades_eventos_json' => $validPayload,
        ]);
        $suppressed = $this->createCambio([
            'feed_status' => CambioFeedStatus::Suppressed,
            'autoridades_eventos_json' => $validPayload,
        ]);
        $sourceHealth = $this->createCambio([
            'feed_status' => CambioFeedStatus::SourceHealth,
            'autoridades_eventos_json' => $validPayload,
        ]);
        $empty = $this->createCambio([
            'feed_status' => CambioFeedStatus::Primary,
            'autoridades_eventos_json' => ['version' => 1, 'events' => []],
        ]);
        $malformed = $this->createCambio([
            'feed_status' => CambioFeedStatus::Primary,
            'autoridades_eventos_json' => ['version' => 1, 'events' => [[
                'type' => 'reemplazo',
                'old' => $this->authority('Director', 'Ana'),
                'new' => null,
            ]]],
        ]);
        $unknown = $this->createCambio([
            'feed_status' => CambioFeedStatus::Primary,
            'autoridades_eventos_json' => ['version' => 1, 'events' => [[
                'type' => 'edicion',
                'old' => null,
                'new' => $this->authority('Director', 'Ana'),
            ]]],
        ]);
        $geminiOnly = $this->createCambio([
            'gemini_analyzed' => true,
            'gemini_analisis_json' => ['persona_nueva' => 'Ana', 'riesgo' => 'alto'],
        ]);
        $personProxy = $this->createCambio(['posibles_peps' => 'Ana Pérez']);
        $editorial = $this->createCambio(['diff_texto' => '+ Nueva navegación']);
        $legacy = $this->createCambio();

        $this->assertSame(CambioFeedStatus::Review, $legacy->fresh()->feed_status);
        $this->assertSame([], Cambio::primaryFeed()->pluck('id')->all());
        $this->assertEqualsCanonicalizing(
            [$review->id, $geminiOnly->id, $personProxy->id, $editorial->id, $legacy->id],
            Cambio::reviewFeed()->pluck('id')->all(),
        );
        $this->assertNotContains($suppressed->id, Cambio::primaryFeed()->pluck('id')->all());
        $this->assertNotContains($sourceHealth->id, Cambio::primaryFeed()->pluck('id')->all());
        $this->assertNotContains($empty->id, Cambio::primaryFeed()->pluck('id')->all());
        $this->assertNotContains($malformed->id, Cambio::primaryFeed()->pluck('id')->all());
        $this->assertNotContains($unknown->id, Cambio::primaryFeed()->pluck('id')->all());
    }

    public function test_primary_scope_requires_exact_integer_json_version(): void
    {
        $exactInteger = $this->createCambio([
            'feed_status' => CambioFeedStatus::Primary,
            'autoridades_eventos_json' => $this->payload(),
        ]);
        $numericFloat = $this->createCambioWithRawPayload(
            '{"version":1.0,"events":[{"type":"designacion","old":null,"new":{"cargo":"Directora","persona":"Ana Pérez"}}]}',
        );

        $this->assertSame([$exactInteger->id], Cambio::primaryFeed()->pluck('id')->all());
        $this->assertNotContains($numericFloat->id, Cambio::primaryFeed()->pluck('id')->all());
    }

    public function test_primary_scope_safely_excludes_non_array_events(): void
    {
        $nonArrayEvents = $this->createCambioWithRawPayload(
            '{"version":1,"events":{"type":"designacion","old":null,"new":{"cargo":"Directora","persona":"Ana Pérez"}}}',
        );

        $this->assertSame([], Cambio::primaryFeed()->whereKey($nonArrayEvents->id)->pluck('id')->all());
    }

    public function test_primary_scope_safely_excludes_primitive_events(): void
    {
        $primitiveEvent = $this->createCambioWithRawPayload(
            '{"version":1,"events":["invalid"]}',
        );

        $this->assertSame([], Cambio::primaryFeed()->whereKey($primitiveEvent->id)->pluck('id')->all());
    }

    public function test_primary_scope_rejects_unicode_whitespace_only_authority_fields(): void
    {
        $invalidValues = ['', '   ', "\t\n", "\u{00A0}"];
        $invalidIds = [];

        foreach ($invalidValues as $value) {
            foreach (['cargo', 'persona'] as $field) {
                $authority = $this->authority('Directora', 'Ana Pérez');
                $authority[$field] = $value;
                $invalidIds[] = $this->createCambio([
                    'feed_status' => CambioFeedStatus::Primary,
                    'autoridades_eventos_json' => [
                        'version' => 1,
                        'events' => [['type' => 'designacion', 'old' => null, 'new' => $authority]],
                    ],
                ])->id;
            }
        }

        $this->assertSame([], Cambio::primaryFeed()->whereKey($invalidIds)->pluck('id')->all());
    }

    /** @param array<string, scalar|array|null> $attributes */
    private function createCambio(array $attributes = []): Cambio
    {
        return Cambio::withoutEvents(fn (): Cambio => Cambio::create(array_merge([
            'fuente_id' => $this->fuente->id,
            'hash_anterior' => fake()->sha256(),
            'hash_nuevo' => fake()->sha256(),
        ], $attributes)));
    }

    private function createCambioWithRawPayload(string $payload): Cambio
    {
        $id = DB::table('cambios')->insertGetId([
            'fuente_id' => $this->fuente->id,
            'hash_anterior' => fake()->sha256(),
            'hash_nuevo' => fake()->sha256(),
            'autoridades_eventos_json' => $payload,
            'feed_status' => CambioFeedStatus::Primary->value,
        ]);

        return Cambio::query()->findOrFail($id);
    }

    /** @return array{version:int,events:array<int,array<string,array<string,string>|string|null>>} */
    private function payload(): array
    {
        return ['version' => 1, 'events' => [[
            'type' => 'designacion',
            'old' => null,
            'new' => $this->authority('Directora', 'Ana Pérez'),
        ]]];
    }

    /** @return array{cargo:string,persona:string} */
    private function authority(string $cargo, string $persona): array
    {
        return ['cargo' => $cargo, 'persona' => $persona];
    }
}
