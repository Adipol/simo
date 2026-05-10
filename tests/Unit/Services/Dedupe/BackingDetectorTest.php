<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dedupe;

use App\Services\Dedupe\BackingDetector;
use Tests\TestCase;

/**
 * P4.T1 — RED tests for BackingDetector::score() and hasAny().
 *
 * All 5 keyword categories tested independently.
 * Case-insensitive and multi-match verified.
 */
class BackingDetectorTest extends TestCase
{
    private BackingDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new BackingDetector();
    }

    // ─── score() basic ────────────────────────────────────────────────────────

    public function test_score_returns_0_for_empty_string(): void
    {
        $this->assertSame(0, $this->detector->score(''));
    }

    public function test_score_returns_0_for_null(): void
    {
        $this->assertSame(0, $this->detector->score(null));
    }

    public function test_score_returns_0_for_text_without_keywords(): void
    {
        $this->assertSame(0, $this->detector->score('El presidente visitó la planta ayer'));
    }

    // ─── NORMATIVA category ───────────────────────────────────────────────────

    public function test_score_detects_normativa_keyword_resolucion(): void
    {
        $score = $this->detector->score('según resolución 123/2026 emitida ayer');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_score_detects_normativa_keyword_decreto_supremo(): void
    {
        $score = $this->detector->score('mediante decreto supremo se designó al funcionario');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_score_detects_normativa_keyword_ley(): void
    {
        $score = $this->detector->score('conforme a la ley vigente');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    // ─── DOCUMENTOS category ─────────────────────────────────────────────────

    public function test_score_detects_documentos_keyword_oficio(): void
    {
        $score = $this->detector->score('según oficio circular emitido');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_score_detects_documentos_keyword_memorandum(): void
    {
        $score = $this->detector->score('en el memorándum oficial se establece');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_score_detects_documentos_keyword_instructivo(): void
    {
        $score = $this->detector->score('según el instructivo publicado');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    // ─── ACCIONES category ────────────────────────────────────────────────────

    public function test_score_detects_acciones_keyword_designado_mediante(): void
    {
        $score = $this->detector->score('fue designado mediante resolución ministerial');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_score_detects_acciones_keyword_posesionado(): void
    {
        $score = $this->detector->score('el funcionario fue posesionado hoy');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    // ─── FUENTE category ──────────────────────────────────────────────────────

    public function test_score_detects_fuente_keyword_gaceta_oficial(): void
    {
        $score = $this->detector->score('publicado en la gaceta oficial del estado');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_score_detects_fuente_keyword_contraloria(): void
    {
        $score = $this->detector->score('según informe de la contraloría');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    // ─── GENERICO category ────────────────────────────────────────────────────

    public function test_score_detects_generico_keyword_segun_consta(): void
    {
        $score = $this->detector->score('según consta en el acta oficial');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    public function test_score_detects_generico_keyword_conforme_a(): void
    {
        $score = $this->detector->score('conforme a lo establecido en el reglamento');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    // ─── Case insensitivity ───────────────────────────────────────────────────

    public function test_score_is_case_insensitive_upper(): void
    {
        $lower = $this->detector->score('según decreto');
        $upper = $this->detector->score('SEGÚN DECRETO');
        $this->assertGreaterThanOrEqual(1, $upper);
        $this->assertSame($lower, $upper);
    }

    public function test_score_is_case_insensitive_mixed(): void
    {
        $score = $this->detector->score('Decreto Supremo No. 4567');
        $this->assertGreaterThanOrEqual(1, $score);
    }

    // ─── Multiple matches sum ─────────────────────────────────────────────────

    public function test_score_increments_for_multiple_keyword_matches(): void
    {
        // Two distinct keywords: "resolución" (NORMATIVA) + "gaceta oficial" (FUENTE)
        $single = $this->detector->score('según resolución administrativa');
        $multiple = $this->detector->score('según resolución administrativa publicada en gaceta oficial');
        $this->assertGreaterThan($single, $multiple);
    }

    public function test_score_with_two_keywords_returns_at_least_2(): void
    {
        // "resolución suprema" (NORMATIVA) + "según consta" (GENERICO)
        $score = $this->detector->score('designado mediante resolución suprema según consta en el documento');
        $this->assertGreaterThanOrEqual(2, $score);
    }

    // ─── hasAny() ─────────────────────────────────────────────────────────────

    public function test_has_any_returns_false_when_score_is_0(): void
    {
        $this->assertFalse($this->detector->hasAny('El precio del dólar subió ayer'));
    }

    public function test_has_any_returns_true_when_score_gt_0(): void
    {
        $this->assertTrue($this->detector->hasAny('según resolución administrativa 123'));
    }

    public function test_has_any_returns_false_for_empty_string(): void
    {
        $this->assertFalse($this->detector->hasAny(''));
    }

    public function test_has_any_returns_false_for_null(): void
    {
        $this->assertFalse($this->detector->hasAny(null));
    }
}
