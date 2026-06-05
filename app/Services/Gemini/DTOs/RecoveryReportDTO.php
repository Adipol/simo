<?php

declare(strict_types=1);

namespace App\Services\Gemini\DTOs;

final readonly class RecoveryReportDTO
{
    public function __construct(
        public int $scanned,
        public int $reset,
        public int $dispatched,
        /**
         * Flash-only field. ResultadoScraping has a relevante column; Cambio does not.
         * Always 0 on the Pro recovery path. RecoverStrandedGeminiPro does not display it.
         */
        public int $relevante,
    ) {}

    /**
     * @param  array{scanned: int, reset: int, dispatched: int, relevante: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            scanned: (int) ($data['scanned'] ?? 0),
            reset: (int) ($data['reset'] ?? 0),
            dispatched: (int) ($data['dispatched'] ?? 0),
            relevante: (int) ($data['relevante'] ?? 0),
        );
    }
}
