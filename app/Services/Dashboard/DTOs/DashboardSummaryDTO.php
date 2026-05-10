<?php

declare(strict_types=1);

namespace App\Services\Dashboard\DTOs;

final readonly class DashboardSummaryDTO
{
    public function __construct(
        public ?HeroCardDTO $hero,
        public TriageStripDTO $triage,
        public BacklogAgeDTO $backlog,
        public RecentDiscoveriesDTO $discoveries,
        public ?\DateTimeImmutable $ultima_actividad_revisada,
    ) {}
}
