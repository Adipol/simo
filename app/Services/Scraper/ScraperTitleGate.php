<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\ResultadoScraping;
use App\Services\Gemini\GeminiFiltroService;
use Illuminate\Support\Facades\Cache;

final class ScraperTitleGate
{
    public function __construct(
        private readonly DeepSeekTitleSettings $settings,
        private readonly DeepSeekTitleClient $client,
        private readonly GeminiFiltroService $gemini,
    ) {}

    public function batchSize(): int
    {
        // Invalid credentials still need a persisted fail-open decision for new rows.
        return $this->settings->enabled && $this->settings->activationAfterId !== null
            ? $this->settings->batchSize : 10;
    }

    public static function lockKey(int $id): string
    {
        return 'scraper-title-gate:'.$id;
    }

    public function process(int $id): void
    {
        // Per-record lease exceeds the 300s job timeout and transport budget:
        // three conservative title phases (connect/header/read), one Gemini call,
        // plus 60s persistence/scheduling margin. Neither client retries internally.
        $lease = max(360, 3 * $this->settings->timeout + (int) config('services.gemini.timeout', 45) + 60);
        Cache::lock(self::lockKey($id), $lease)->get(function () use ($id): void {
            // Never trust the batch snapshot: another worker/bypass may have changed it.
            $record = ResultadoScraping::pendienteAnalisisScraper()->find($id);
            if ($record === null || $record->gemini_analyzed_at !== null) {
                return;
            }

            $bypassLexicalPrefilter = $record->deepseek_bypass_at !== null;
            if (! $bypassLexicalPrefilter) {
                if ($record->deepseek_title_status === null && $this->settings->enabled
                    && $this->settings->activationAfterId !== null && $id > $this->settings->activationAfterId) {
                    // No transaction spans either network call. Durable title state is
                    // saved before Gemini so a normal queue retry reuses the decision.
                    $result = $this->client->classify($id, $record->titulo ?? '');
                    $record->update($result->toPersistenceAttributes());
                }
                if ($record->deepseek_title_status === 'unrelated') {
                    return;
                }
                $bypassLexicalPrefilter = in_array($record->deepseek_title_status,
                    ['relevant', 'doubtful', 'technical_error', 'invalid_response'], true);
            }

            if ($bypassLexicalPrefilter) {
                $this->gemini->analizarLote(collect([$record]), bypassLexicalPrefilter: true);
            } else {
                $this->gemini->analizarLote(collect([$record]));
            }
        });
        // A busy lease skips this article; lock backend failures propagate for retry.
    }
}
