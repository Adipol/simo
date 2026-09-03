<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Pep;

use App\Enums\CambioFeedStatus;
use App\Services\Pep\AuthorityEventFeedService;
use PHPUnit\Framework\TestCase;

final class AuthorityEventFeedServiceTest extends TestCase
{
    private AuthorityEventFeedService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AuthorityEventFeedService;
    }

    public function test_all_four_canonical_event_types_are_valid(): void
    {
        $payload = [
            'version' => 1,
            'events' => [
                ['type' => 'designacion', 'old' => null, 'new' => $this->authority('Ministra', 'Ana Pérez')],
                ['type' => 'remocion', 'old' => $this->authority('Director', 'Luis Soto'), 'new' => null],
                ['type' => 'reemplazo', 'old' => $this->authority('Gerente', 'María Paz'), 'new' => $this->authority('Gerente', 'Juan Solís')],
                ['type' => 'cambio_cargo', 'old' => $this->authority('Asesor', 'Pedro Lima'), 'new' => $this->authority('Viceministro', 'Pedro Lima')],
            ],
        ];

        $this->assertTrue($this->service->isValidPayload($payload));
        $this->assertSame(
            CambioFeedStatus::Primary,
            $this->service->classify($payload, CambioFeedStatus::Primary),
        );
    }

    public function test_empty_malformed_and_unknown_events_are_not_primary_eligible(): void
    {
        $invalidPayloads = [
            null,
            ['version' => 1, 'events' => []],
            ['version' => 2, 'events' => [['type' => 'designacion']]],
            ['version' => 1, 'events' => [['type' => 'edicion', 'old' => null, 'new' => $this->authority('Director', 'Ana')]]],
            ['version' => 1, 'events' => [['type' => 'designacion', 'old' => null, 'new' => ['cargo' => '', 'persona' => 'Ana']]]],
            ['version' => 1, 'events' => [['type' => 'remocion', 'old' => null, 'new' => null]]],
            ['version' => 1, 'events' => [['type' => 'reemplazo', 'old' => $this->authority('Director', 'Ana'), 'new' => null]]],
            ['version' => 1, 'events' => [['type' => 'cambio_cargo', 'old' => $this->authority('Director', 'Ana'), 'new' => ['cargo' => 'Ministra', 'persona' => ' ']]]],
        ];

        foreach ($invalidPayloads as $payload) {
            $this->assertFalse($this->service->isValidPayload($payload));
            $this->assertSame(
                CambioFeedStatus::Review,
                $this->service->classify($payload, CambioFeedStatus::Primary),
            );
        }
    }

    public function test_version_requires_exact_integer_representation(): void
    {
        $this->assertTrue($this->service->isValidPayload($this->payload(version: 1)));
        $this->assertFalse($this->service->isValidPayload($this->payload(version: 1.0)));
    }

    public function test_authority_fields_require_non_whitespace_text(): void
    {
        $values = [
            'ordinary text' => ['Directora', true],
            'empty' => ['', false],
            'ordinary spaces' => ['   ', false],
            'tabs and newlines' => ["\t\n", false],
            'non-breaking spaces' => ["\u{00A0}", false],
        ];

        foreach ($values as $label => [$value, $expected]) {
            foreach (['cargo', 'persona'] as $field) {
                $authority = $this->authority('Directora', 'Ana Pérez');
                $authority[$field] = $value;

                $this->assertSame(
                    $expected,
                    $this->service->isValidPayload([
                        'version' => 1,
                        'events' => [['type' => 'designacion', 'old' => null, 'new' => $authority]],
                    ]),
                    "{$label} in {$field}",
                );
            }
        }
    }

    public function test_non_primary_audit_destinations_are_preserved(): void
    {
        foreach ([CambioFeedStatus::Review, CambioFeedStatus::Suppressed, CambioFeedStatus::SourceHealth] as $destination) {
            $this->assertSame($destination, $this->service->classify(null, $destination));
        }
    }

    /** @return array{cargo:string,persona:string} */
    private function authority(string $cargo, string $persona): array
    {
        return ['cargo' => $cargo, 'persona' => $persona];
    }

    /** @return array{version:int|float,events:list<array{type:string,old:null,new:array{cargo:string,persona:string}}>} */
    private function payload(int|float $version): array
    {
        return [
            'version' => $version,
            'events' => [[
                'type' => 'designacion',
                'old' => null,
                'new' => $this->authority('Directora', 'Ana Pérez'),
            ]],
        ];
    }
}
