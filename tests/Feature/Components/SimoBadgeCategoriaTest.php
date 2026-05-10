<?php

declare(strict_types=1);

namespace Tests\Feature\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class SimoBadgeCategoriaTest extends TestCase
{
    // =========================================================================
    // Cleanup 2 — RED tests: <x-simo-badge-categoria> Blade component
    // =========================================================================

    /**
     * Cleanup 2 — null categoria renders nothing.
     */
    public function test_renders_nothing_when_categoria_is_null(): void
    {
        $html = Blade::render('<x-simo-badge-categoria :categoria="$cat" />', ['cat' => null]);

        $this->assertSame('', trim($html));
    }

    /**
     * Cleanup 2 — PEP categoria renders indigo badge with correct text.
     */
    public function test_renders_pep_badge_with_indigo_classes(): void
    {
        $html = Blade::render('<x-simo-badge-categoria :categoria="$cat" />', ['cat' => 'PEP']);

        $this->assertStringContainsString('PEP', $html);
        $this->assertStringContainsString('bg-indigo-50', $html);
        $this->assertStringContainsString('text-indigo-600', $html);
    }

    /**
     * Cleanup 2 — OPI categoria renders amber badge with correct text.
     */
    public function test_renders_opi_badge_with_amber_classes(): void
    {
        $html = Blade::render('<x-simo-badge-categoria :categoria="$cat" />', ['cat' => 'OPI']);

        $this->assertStringContainsString('OPI', $html);
        $this->assertStringContainsString('bg-amber-50', $html);
        $this->assertStringContainsString('text-amber-600', $html);
    }

    /**
     * Cleanup 2 — unknown categoria renders with neutral/default classes.
     */
    public function test_renders_unknown_categoria_with_default_classes(): void
    {
        $html = Blade::render('<x-simo-badge-categoria :categoria="$cat" />', ['cat' => 'MAGISTRADO']);

        $this->assertStringContainsString('MAGISTRADO', $html);
        $this->assertStringContainsString('bg-zinc-100', $html);
        $this->assertStringContainsString('text-zinc-500', $html);
    }

    /**
     * Cleanup 2 — additional CSS classes merge via $attributes.
     */
    public function test_merges_additional_classes_via_attributes(): void
    {
        $html = Blade::render(
            '<x-simo-badge-categoria :categoria="$cat" class="ml-2" />',
            ['cat' => 'PEP']
        );

        $this->assertStringContainsString('ml-2', $html);
        $this->assertStringContainsString('PEP', $html);
    }
}
