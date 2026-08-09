<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Gemini;

use App\Models\ResultadoScraping;
use App\Services\Gemini\SportsNoiseCandidateService;
use Tests\TestCase;

class SportsNoiseCandidateServiceTest extends TestCase
{
    public function test_it_classifies_a_narrow_private_club_role_status_event_with_stable_bounded_codes(): void
    {
        config()->set('services.sports_noise', $this->validConfiguration());

        $decision = (new SportsNoiseCandidateService)->decide($this->record(
            'Carlos Pérez fue designado entrenador del Club Aurora.',
            'El Club Aurora designó a Carlos Pérez como entrenador para la temporada.',
        ));

        $this->assertSame('candidate', $decision->outcome);
        $this->assertSame('sports-noise-v1', $decision->ruleVersion);
        $this->assertSame(['club_private', 'role_coach', 'status_appointed'], $decision->reasonCodes);
        $this->assertLessThanOrEqual(3, count($decision->reasonCodes));
        $this->assertSame(hash('sha256', 'Carlos Pérez fue designado entrenador del Club Aurora.'), $decision->titleFingerprint);
        $this->assertNull($decision->excerpt);
    }

    public function test_it_requires_role_status_and_private_team_evidence_instead_of_generic_sports_words(): void
    {
        config()->set('services.sports_noise', $this->validConfiguration());

        $decision = (new SportsNoiseCandidateService)->decide($this->record(
            'El Club Aurora prepara un partido importante.',
            'El equipo entrenó antes del próximo torneo.',
        ));

        $this->assertSame('pass_open', $decision->outcome);
        $this->assertSame(['incomplete_evidence'], $decision->escapeCodes);
    }

    public function test_escape_evidence_and_invalid_configuration_always_pass_open_before_sports_evidence(): void
    {
        config()->set('services.sports_noise', $this->validConfiguration());
        $service = new SportsNoiseCandidateService;

        $cases = [
            'BoA' => ['La BoA investiga al entrenador designado por el Club Aurora.', ['boa_opi_context']],
            'OPI' => ['La investigación por OPI alcanza al entrenador designado por el Club Aurora.', ['boa_opi_context']],
            'public resource' => ['El entrenador fue designado por la empresa estatal de deportes Club Aurora.', ['public_resource']],
            'governing body' => ['La federación designó al entrenador del Club Aurora.', ['governing_body']],
            'foreign official' => ['El ministro de deportes de Perú designó al entrenador del Club Aurora.', ['foreign_official']],
            'ambiguous role' => ['El presidente del Club Aurora fue designado para dirigir el equipo.', ['ambiguous_role']],
        ];

        foreach ($cases as [$title, $expectedCodes]) {
            $decision = $service->decide($this->record($title, $title));

            $this->assertSame('pass_open', $decision->outcome);
            $this->assertSame($expectedCodes, $decision->escapeCodes);
        }

        $multipleEscapes = $service->decide($this->record(
            'El presidente de la federación y ministro designó al entrenador del Club Aurora.',
            'El presidente de la federación y ministro designó al entrenador del Club Aurora.',
        ));

        $this->assertSame(
            ['ambiguous_role', 'foreign_official', 'governing_body'],
            $multipleEscapes->escapeCodes,
        );
        $this->assertLessThanOrEqual(3, count($multipleEscapes->escapeCodes));

        config()->set('services.sports_noise', ['enabled' => true, 'catalog' => 'invalid']);

        $invalidConfiguration = $service->decide($this->record(
            'Carlos Pérez fue designado entrenador del Club Aurora.',
            'El Club Aurora designó a Carlos Pérez como entrenador.',
        ));

        $this->assertSame('pass_open', $invalidConfiguration->outcome);
        $this->assertSame(['invalid_configuration'], $invalidConfiguration->escapeCodes);

        $malformedCatalog = $this->validConfiguration();
        $malformedCatalog['catalog']['escape_terms']['foreign_official'] = 'ministro';
        config()->set('services.sports_noise', $malformedCatalog);

        $invalidCatalog = $service->decide($this->record(
            'Carlos Pérez fue designado entrenador del Club Aurora.',
            'El Club Aurora designó a Carlos Pérez como entrenador.',
        ));

        $this->assertSame('pass_open', $invalidCatalog->outcome);
        $this->assertSame(['invalid_configuration'], $invalidCatalog->escapeCodes);

        config()->set('services.sports_noise', ['enabled' => false, 'catalog' => $this->validConfiguration()['catalog']]);

        $disabled = $service->decide($this->record(
            'Carlos Pérez fue designado entrenador del Club Aurora.',
            'El Club Aurora designó a Carlos Pérez como entrenador.',
        ));

        $this->assertSame('pass_open', $disabled->outcome);
        $this->assertSame(['disabled'], $disabled->escapeCodes);
    }

    /** @return array<string, mixed> */
    private function validConfiguration(): array
    {
        return [
            'enabled' => true,
            'catalog' => [
                'club_terms' => ['club', 'equipo'],
                'role_terms' => ['entrenador', 'coach'],
                'status_terms' => ['designado', 'nombrado'],
                'escape_terms' => [
                    'boa_opi_context' => ['boa', 'opi'],
                    'public_resource' => ['empresa estatal'],
                    'governing_body' => ['federación', 'asociación'],
                    'foreign_official' => ['ministro', 'perú'],
                    'ambiguous_role' => ['presidente'],
                ],
            ],
        ];
    }

    private function record(string $title, string $context): ResultadoScraping
    {
        return new ResultadoScraping([
            'titulo' => $title,
            'contexto' => $context,
        ]);
    }
}
