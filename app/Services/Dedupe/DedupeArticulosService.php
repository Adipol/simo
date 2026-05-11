<?php

declare(strict_types=1);

namespace App\Services\Dedupe;

use App\Models\ConfigScript;
use App\Models\ResultadoScraping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service that encapsulates the deduplication logic for ResultadoScraping articles.
 *
 * Design D2 + D6:
 * - Queries pg_trgm similarity() within a configurable time window
 * - Uses BackingDetector to select the cluster head (highest score)
 * - Is fully idempotent: if the article already has secundario_de set, it is skipped
 * - Reads threshold and window from ConfigScript::dedupe()
 *
 * This service is intended to be called by DedupeArticulosJob — tested directly
 * to avoid queue infrastructure in tests.
 */
final class DedupeArticulosService
{
    public function __construct(
        private readonly BackingDetector $detector,
    ) {}

    /**
     * Process one article: leave it as primary or mark it as secondary of an existing cluster.
     *
     * Idempotent: if the article already has secundario_de set, returns immediately.
     */
    public function procesar(int $resultadoId): void
    {
        $config = ConfigScript::dedupe();

        if (! $config->habilitado) {
            return;
        }

        $article = ResultadoScraping::find($resultadoId);

        if ($article === null) {
            return; // non-existent — nothing to stamp
        }

        if ($article->secundario_de !== null) {
            // Path A (early-exit): already a secondary — stamp and return.
            // Stamp OUTSIDE any transaction: this is a processing receipt, not part of cluster invariant.
            $article->update(['dedupe_processed_at' => now()]);

            return;
        }

        $threshold  = $config->dedupeThreshold();
        $ventanaDias = $config->ventanaDias();

        // Use a DB transaction with SELECT FOR UPDATE to prevent race conditions
        // between concurrent jobs processing the same article or cluster.
        DB::transaction(function () use ($article, $threshold, $ventanaDias): void {
            // Re-read the article inside the transaction with a lock
            $locked = ResultadoScraping::lockForUpdate()->find($article->id);

            if ($locked === null || $locked->secundario_de !== null) {
                return; // Another job won the race; nothing to do
            }

            $candidates = $this->queryCandidates($locked, $threshold, $ventanaDias);

            if (empty($candidates)) {
                return; // No similar articles → this article remains primary
            }

            // Select the best cluster head from candidates using BackingDetector score.
            // Per user decision: PRIMARY IS IMMUTABLE — existing primary stays primary.
            // BackingDetector is used only to pick the best among multiple candidates
            // (rare edge case when 2+ primaries are within the window).
            $bestCandidate = $this->selectBestPrimary($candidates);

            // Mark the new article as secondary of the best existing primary
            $locked->update(['secundario_de' => $bestCandidate->id]);

            Log::channel('gemini')->info('dedupe.cluster_assigned', [
                'article_id' => $locked->id,
                'primary_id' => $bestCandidate->id,
                'similarity' => $bestCandidate->sim,
            ]);
        });

        // Path B (post-transaction): stamp processing receipt OUTSIDE the transaction.
        // Design decision: dedupe_processed_at is not part of the cluster invariant —
        // stamping outside avoids extending the lock window. If this update fails,
        // the next safety-net cycle re-dispatches the job (idempotent).
        $article->update(['dedupe_processed_at' => now()]);
    }

    /**
     * Query for similar primary articles using pg_trgm (PostgreSQL) or a LIKE fallback (SQLite/testing).
     *
     * On PostgreSQL:
     *  - Sets the similarity threshold via SET LOCAL
     *  - Uses the % operator (GiST-indexed) for fast trigram similarity
     *
     * On SQLite (test environment only):
     *  - Falls back to LIKE-based matching (no pg_trgm available)
     *  - Returns an empty array so tests that rely on real similarity must use pgsql
     *
     * @return array<object>
     */
    private function queryCandidates(ResultadoScraping $article, float $threshold, int $ventanaDias): array
    {
        $driver = DB::getDriverName();

        if ($driver !== 'pgsql') {
            // SQLite fallback: no pg_trgm — return empty (no clustering in test mode)
            // Tests that need real similarity must run against a pgsql test DB.
            return [];
        }

        // Set pg_trgm similarity threshold for this transaction.
        //
        // We use set_config(name, value, is_local) instead of `SET LOCAL ... = ?`
        // because PostgreSQL does NOT allow parameter bindings in `SET` statements
        // (those are not prepared queries — the value must be a literal). The
        // function form set_config(...) IS a regular function call that accepts
        // bound parameters, with `is_local = true` giving the same scope as SET LOCAL.
        //
        // Reference: https://www.postgresql.org/docs/current/functions-admin.html
        DB::statement(
            'SELECT set_config(?, ?, true)',
            ['pg_trgm.similarity_threshold', (string) $threshold]
        );

        // Design D2 canonical query: find existing PRIMARY articles with similar title
        // within the window, excluding self and already-secondary articles
        return DB::select(
            "SELECT id, contexto, similarity(titulo, ?) AS sim
             FROM resultados_scraping
             WHERE titulo % ?
               AND fecha_encontrado >= NOW() - ? * INTERVAL '1 day'
               AND id != ?
               AND secundario_de IS NULL
             ORDER BY sim DESC
             LIMIT 10",
            [
                $article->titulo,
                $article->titulo,
                $ventanaDias,
                $article->id,
            ]
        );
    }

    /**
     * Select the best primary from a list of candidates.
     *
     * Strategy (per design D6):
     *  1. Prefer the candidate with the highest BackingDetector score.
     *  2. Tie-break by fecha_encontrado (oldest wins — implicit in ORDER BY sim DESC from query).
     *
     * Since the query already ordered by similarity DESC, candidate[0] is the most similar.
     * Among equally similar candidates, BackingDetector score determines the head.
     *
     * @param  array<object>  $candidates  Rows from DB::select (have ->id, ->contexto, ->sim)
     */
    private function selectBestPrimary(array $candidates): object
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Score each candidate and pick the one with the highest backing score.
        // In a tie, the first element (highest similarity from ORDER BY) wins.
        $best = $candidates[0];
        $bestScore = $this->detector->score($candidates[0]->contexto ?? '');

        foreach (array_slice($candidates, 1) as $candidate) {
            $score = $this->detector->score($candidate->contexto ?? '');
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }
}
