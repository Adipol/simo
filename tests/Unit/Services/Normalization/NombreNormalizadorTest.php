<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Normalization;

use App\Services\Normalization\NombreNormalizador;
use Tests\TestCase;

class NombreNormalizadorTest extends TestCase
{
    private NombreNormalizador $normalizador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizador = new NombreNormalizador;
    }

    // ─── R1: Trim Whitespace ─────────────────────────────────────────────────

    public function test_r1_leading_whitespace_is_removed(): void
    {
        $dto = $this->normalizador->normalize('  Juan');

        $this->assertStringStartsWith('Juan', $dto->normalized);
    }

    public function test_r1_trailing_whitespace_is_removed(): void
    {
        $dto = $this->normalizador->normalize('Pérez  ');

        $this->assertStringEndsWith('Pérez', $dto->normalized);
    }

    public function test_r1_both_leading_and_trailing_whitespace_removed(): void
    {
        $dto = $this->normalizador->normalize('  María García  ');

        $this->assertSame('María García', $dto->normalized);
    }

    public function test_r1_original_preserves_input_verbatim(): void
    {
        $input = '  Juan Pérez  ';
        $dto = $this->normalizador->normalize($input);

        $this->assertSame($input, $dto->original);
    }

    // ─── R2: Collapse Spaces ─────────────────────────────────────────────────

    public function test_r2_multiple_spaces_collapsed_to_single(): void
    {
        $dto = $this->normalizador->normalize('Juan   Pérez');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_r2_tabs_collapsed_to_single_space(): void
    {
        $dto = $this->normalizador->normalize("María\t\tGarcía");

        $this->assertSame('María García', $dto->normalized);
    }

    public function test_r2_mixed_whitespace_collapsed(): void
    {
        $dto = $this->normalizador->normalize("Juan \t  Pérez");

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    // ─── R3: Academic Titles ─────────────────────────────────────────────────

    public function test_r3_removes_dr_with_period(): void
    {
        $dto = $this->normalizador->normalize('Dr. Juan Pérez');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_r3_removes_dra_with_period(): void
    {
        $dto = $this->normalizador->normalize('Dra. María García');

        $this->assertSame('María García', $dto->normalized);
    }

    public function test_r3_removes_licdo(): void
    {
        $dto = $this->normalizador->normalize('Licdo. José López');

        $this->assertSame('José López', $dto->normalized);
    }

    public function test_r3_removes_ing(): void
    {
        $dto = $this->normalizador->normalize('Ing. Carlos Ruiz');

        $this->assertSame('Carlos Ruiz', $dto->normalized);
    }

    public function test_r3_removes_prof(): void
    {
        $dto = $this->normalizador->normalize('Prof. Ana Soto');

        $this->assertSame('Ana Soto', $dto->normalized);
    }

    public function test_r3_does_not_remove_title_not_at_start(): void
    {
        $dto = $this->normalizador->normalize('Jefe Juan Pérez');

        // "Jefe" is not in title list, so not removed
        $this->assertStringContainsString('Jefe', $dto->normalized);
    }

    public function test_r3_case_insensitive_dr_lowercase(): void
    {
        $dto = $this->normalizador->normalize('dr. Juan Pérez');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    // ─── R4: Courtesy Titles ─────────────────────────────────────────────────

    public function test_r4_removes_sr_with_period(): void
    {
        $dto = $this->normalizador->normalize('Sr. Juan Pérez');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_r4_removes_sra_with_period(): void
    {
        $dto = $this->normalizador->normalize('Sra. María García');

        $this->assertSame('María García', $dto->normalized);
    }

    public function test_r4_removes_don_without_period(): void
    {
        $dto = $this->normalizador->normalize('Don José López');

        $this->assertSame('José López', $dto->normalized);
    }

    public function test_r4_removes_dona_with_tilde(): void
    {
        $dto = $this->normalizador->normalize('Doña Carmen Ruiz');

        $this->assertSame('Carmen Ruiz', $dto->normalized);
    }

    public function test_r4_removes_ab_with_period(): void
    {
        $dto = $this->normalizador->normalize('Ab. Pedro Gomez');

        $this->assertSame('Pedro Gomez', $dto->normalized);
    }

    public function test_r4_does_not_remove_title_in_middle(): void
    {
        $dto = $this->normalizador->normalize('Juan Sr. Pérez');

        $this->assertStringContainsString('Sr', $dto->normalized);
    }

    // ─── R5: Title Case ──────────────────────────────────────────────────────

    public function test_r5_all_uppercase_converted_to_title_case(): void
    {
        $dto = $this->normalizador->normalize('JUAN PÉREZ');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_r5_all_uppercase_with_accented_chars(): void
    {
        $dto = $this->normalizador->normalize('MARÍA GARCÍA');

        $this->assertSame('María García', $dto->normalized);
    }

    public function test_r5_all_lowercase_converted_to_title_case(): void
    {
        $dto = $this->normalizador->normalize('juan');

        $this->assertSame('Juan', $dto->normalized);
    }

    public function test_r5_title_removed_then_title_case_applied(): void
    {
        // DRA. is removed (R3), then title case applied to MARÍA → María
        $dto = $this->normalizador->normalize('DRA. MARÍA');

        $this->assertSame('María', $dto->normalized);
    }

    // ─── R6: Accent Stripping (matchingKey only) ──────────────────────────────

    public function test_r6_matching_key_strips_accents_and_lowercases(): void
    {
        $dto = $this->normalizador->normalize('Juan Pérez');

        $this->assertSame('juan perez', $dto->matchingKey);
    }

    public function test_r6_maria_accent_stripped_in_key(): void
    {
        $dto = $this->normalizador->normalize('María');

        $this->assertSame('maria', $dto->matchingKey);
    }

    public function test_r6_enye_stripped_in_matching_key(): void
    {
        $dto = $this->normalizador->normalize('ñ');

        $this->assertSame('n', $dto->matchingKey);
    }

    public function test_r6_umlaut_stripped_in_matching_key(): void
    {
        $dto = $this->normalizador->normalize('ü');

        $this->assertSame('u', $dto->matchingKey);
    }

    public function test_r6_normalized_property_still_has_accents(): void
    {
        $dto = $this->normalizador->normalize('María');

        $this->assertSame('María', $dto->normalized);
    }

    public function test_r6_normalized_still_has_accent_after_full_pipeline(): void
    {
        $dto = $this->normalizador->normalize('Dr. Juan Pérez');

        $this->assertSame('Juan Pérez', $dto->normalized);
        $this->assertSame('juan perez', $dto->matchingKey);
    }

    // ─── R7: Trailing Punctuation ────────────────────────────────────────────

    public function test_r7_single_trailing_period_removed(): void
    {
        $dto = $this->normalizador->normalize('Juan Pérez.');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_r7_multiple_trailing_periods_all_removed(): void
    {
        $dto = $this->normalizador->normalize('María García...');

        $this->assertSame('María García', $dto->normalized);
    }

    public function test_r7_mixed_trailing_punctuation_all_removed(): void
    {
        $dto = $this->normalizador->normalize('Juan Pérez:,;');

        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_r7_trailing_colon_removed(): void
    {
        $dto = $this->normalizador->normalize('Ana Torres:');

        $this->assertSame('Ana Torres', $dto->normalized);
    }

    // ─── Combined Pipeline ────────────────────────────────────────────────────

    public function test_pipeline_full_complex_input(): void
    {
        $dto = $this->normalizador->normalize('  Dr.   JUAN  PÉREZ.  ');

        $this->assertSame('Juan Pérez', $dto->normalized);
        $this->assertSame('juan perez', $dto->matchingKey);
    }

    public function test_pipeline_dra_all_caps_with_trailing_periods(): void
    {
        $dto = $this->normalizador->normalize('Dra. María DEL CARMEN Pérez...');

        $this->assertSame('María Del Carmen Pérez', $dto->normalized);
    }

    // ─── Edge Cases ───────────────────────────────────────────────────────────

    public function test_normalize_nullable_returns_null_for_null(): void
    {
        $dto = $this->normalizador->normalizeNullable(null);

        $this->assertNull($dto);
    }

    public function test_normalize_nullable_returns_null_for_empty_string(): void
    {
        $dto = $this->normalizador->normalizeNullable('');

        $this->assertNull($dto);
    }

    public function test_normalize_nullable_returns_null_for_whitespace_only(): void
    {
        $dto = $this->normalizador->normalizeNullable('   ');

        $this->assertNull($dto);
    }

    public function test_normalize_nullable_returns_dto_for_valid_string(): void
    {
        $dto = $this->normalizador->normalizeNullable('Juan Pérez');

        $this->assertNotNull($dto);
        $this->assertSame('Juan Pérez', $dto->normalized);
    }

    public function test_single_word_name_works_correctly(): void
    {
        $dto = $this->normalizador->normalize('Pérez');

        $this->assertSame('Pérez', $dto->normalized);
        $this->assertSame('perez', $dto->matchingKey);
    }

    public function test_single_word_lowercase_converted_to_title_case(): void
    {
        $dto = $this->normalizador->normalize('juan');

        $this->assertSame('Juan', $dto->normalized);
    }

    public function test_hyphenated_surname_preserved_in_normalized(): void
    {
        $dto = $this->normalizador->normalize('García-López');

        $this->assertSame('García-López', $dto->normalized);
    }

    public function test_hyphenated_surname_accents_stripped_in_matching_key(): void
    {
        $dto = $this->normalizador->normalize('García-López');

        $this->assertSame('garcia-lopez', $dto->matchingKey);
    }

    public function test_apostrophe_preserved_in_normalized(): void
    {
        // PHP's mb_convert_case(MB_CASE_TITLE) treats apostrophe as a word separator
        // and lowercases the character after it. Input "D'Elía" → "D'elía" (lowercase e after apostrophe).
        // This is consistent with ADR-4 (mb_convert_case, deterministic).
        $dto = $this->normalizador->normalize("D'Elía Martínez");

        $this->assertStringContainsString("D'", $dto->normalized);
        $this->assertStringContainsString('Martínez', $dto->normalized);
    }

    public function test_apostrophe_accents_stripped_in_matching_key(): void
    {
        $dto = $this->normalizador->normalize("D'Elía Martínez");

        $this->assertStringContainsString("d'", $dto->matchingKey);
        $this->assertStringContainsString('martinez', $dto->matchingKey);
    }

    public function test_title_in_middle_is_not_removed(): void
    {
        $dto = $this->normalizador->normalize('Juan Dr. Pérez');

        // "Dr." is in the middle, NOT at the start, so not removed
        $this->assertStringContainsString('Dr', $dto->normalized);
    }

    public function test_non_spanish_characters_pass_through(): void
    {
        $dto = $this->normalizador->normalize('Zhang Wei');

        $this->assertSame('Zhang Wei', $dto->normalized);
        $this->assertSame('zhang wei', $dto->matchingKey);
    }

    // ─── Determinism ─────────────────────────────────────────────────────────

    public function test_normalize_is_deterministic_five_calls(): void
    {
        $input = 'Dr. Juan Pérez';
        $first = $this->normalizador->normalize($input);

        for ($i = 0; $i < 4; $i++) {
            $next = $this->normalizador->normalize($input);
            $this->assertSame($first->normalized, $next->normalized, "Call $i returned different normalized");
            $this->assertSame($first->matchingKey, $next->matchingKey, "Call $i returned different matchingKey");
        }
    }

    public function test_different_names_produce_different_keys(): void
    {
        $a = $this->normalizador->normalize('Juan Pérez');
        $b = $this->normalizador->normalize('María García');

        $this->assertNotSame($a->matchingKey, $b->matchingKey);
    }

    public function test_name_variations_produce_same_matching_key(): void
    {
        $a = $this->normalizador->normalize('Dr. Juan Pérez');
        $b = $this->normalizador->normalize('JUAN PÉREZ');
        $c = $this->normalizador->normalize('juan perez');

        $this->assertSame('juan perez', $a->matchingKey);
        $this->assertSame('juan perez', $b->matchingKey);
        $this->assertSame('juan perez', $c->matchingKey);
    }
}
