<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Pep;

use App\Livewire\Pep\Fuentes;
use App\Models\AuthorityExtractionPreview;
use App\Models\Fuente;
use App\Services\Fuente\FuenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

final class FuentesAuthorityPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function config(): array
    {
        return [
            'version' => 1, 'container_selector' => 'main', 'row_selector' => 'article',
            'person_selector' => '.person', 'role_selector' => '.role', 'min_rows' => 1, 'max_rows' => 200,
        ];
    }

    private function source(string $url = 'https://example.test/officials'): Fuente
    {
        return app(FuenteService::class)->crear([
            'url' => $url, 'nombre' => 'Official roster',
            'tipo' => 'html', 'nivel' => 'nacional', 'activo' => true,
            'autoridades_extractor' => 'structured_css_v1', 'authority_extraction_config' => $this->config(),
        ]);
    }

    private function ready(Fuente $source, array $roster = [['cargo' => 'Rector', 'persona' => 'Ana']]): AuthorityExtractionPreview
    {
        return AuthorityExtractionPreview::create([
            'fuente_id' => $source->id, 'source_revision' => $source->authority_extraction_revision,
            'source_url' => $source->url, 'status' => 'ready', 'attempts' => 1,
            'config_fingerprint' => str_repeat('a', 64), 'authorities_json' => $roster,
            'requested_at' => now(), 'completed_at' => now(),
        ]);
    }

    public function test_generic_create_is_inactive_unconfirmed_and_reopens_configuration_then_new_modal_resets(): void
    {
        $component = Livewire::test(Fuentes::class)->call('abrirModal')
            ->assertSee('CSS estructurado')
            ->set('url', 'https://example.test/officials')->set('tipo', 'html')
            ->set('autoridades_extractor', 'structured_css_v1')
            ->set('containerSelector', 'main')->set('rowSelector', 'article')
            ->set('personSelector', '.person')->set('roleSelector', '.role')
            ->call('guardar')->assertHasNoErrors();
        $source = Fuente::query()->sole();
        $this->assertFalse($source->activo);
        $this->assertNull($source->authority_extraction_confirmed_revision);
        $this->assertSame($this->config(), $source->authority_extraction_config);
        $component->call('abrirModal', $source->id)->assertSet('containerSelector', 'main')
            ->assertSet('maxRows', '200')->set('rowSelector', '.new-row')->call('guardar')->assertHasNoErrors();
        $this->assertSame('.new-row', $source->fresh()->authority_extraction_config['row_selector']);
        $component->call('abrirModal')->assertSet('containerSelector', '')->assertSet('rowSelector', '')
            ->assertSet('minRows', '1')->assertSet('maxRows', '200');
    }

    public function test_invalid_selectors_and_row_bounds_are_visible_and_not_saved(): void
    {
        Livewire::test(Fuentes::class)->call('abrirModal')->set('url', 'https://example.test/officials')
            ->set('autoridades_extractor', 'structured_css_v1')->set('containerSelector', '')
            ->set('rowSelector', str_repeat('x', 501))->set('personSelector', '.person')
            ->set('roleSelector', '.role')->set('minRows', '2')->set('maxRows', '1')
            ->call('guardar')->assertHasErrors(['containerSelector', 'rowSelector', 'maxRows']);
        $this->assertDatabaseCount('fuentes', 0);
    }

    public function test_switching_to_legacy_clears_configuration_and_invalidates_preview(): void
    {
        $source = $this->source();
        $preview = $this->ready($source);
        Livewire::test(Fuentes::class)->call('abrirModal', $source->id)
            ->set('autoridades_extractor', 'divi_blurb')->call('guardar')->assertHasNoErrors();
        $this->assertNull($source->fresh()->authority_extraction_config);
        $this->assertSame('stale', $preview->fresh()->status);
        $this->assertNull($preview->fresh()->authorities_json);
    }

    public function test_request_is_explicit_and_has_no_monitoring_side_effects(): void
    {
        $source = $this->source();
        Livewire::test(Fuentes::class)->assertSee('Solicitar vista previa')
            ->call('solicitarPreview', $source->id)->assertSee('Pendiente');
        $this->assertSame('pending', $source->authorityExtractionPreview()->sole()->status);
        $this->assertDatabaseCount('snapshots', 0);
        $this->assertDatabaseCount('cambios', 0);
        $this->assertFalse($source->fresh()->activo);
    }

    public function test_ready_roster_escapes_text_and_confirmation_never_activates_automatically(): void
    {
        $source = $this->source();
        $this->ready($source, [['cargo' => '<script>alert(1)</script>', 'persona' => '<b>Ana</b>']]);
        $component = Livewire::test(Fuentes::class)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;b&gt;Ana&lt;/b&gt;', false)
            ->assertSee('Confirmar vista previa');
        $component->call('confirmarPreview', $source->id, $this->displayedToken($component->html()))
            ->assertHasNoErrors();
        $this->assertSame($source->authority_extraction_revision, $source->fresh()->authority_extraction_confirmed_revision);
        $this->assertFalse($source->fresh()->activo);
        $component->call('toggleActivo', $source->id);
        $this->assertTrue($source->fresh()->activo);
    }

    private function displayedToken(string $html): string
    {
        $this->assertSame(1, preg_match("/confirmarPreview\\(\\d+, '([a-f0-9]{64})'\\)/", $html, $matches));

        return $matches[1];
    }

    public function test_old_displayed_click_rejects_replacement_then_current_displayed_click_confirms_without_activation(): void
    {
        $source = $this->source();
        $preview = $this->ready($source);
        $component = Livewire::test(Fuentes::class)->assertSee('Ana');
        $tokenA = $this->displayedToken($component->html());
        app(FuenteService::class)->solicitarPreviewAutoridades($source->id);
        $preview->refresh()->update([
            'status' => 'ready', 'completed_at' => now(), 'config_fingerprint' => str_repeat('a', 64),
            'authorities_json' => [['cargo' => 'Rector', 'persona' => 'Beatriz']],
        ]);
        $before = $preview->fresh()->getAttributes();
        $component->call('confirmarPreview', $source->id, $tokenA)->assertHasErrors(['preview'])
            ->assertSee('Beatriz')->assertSee('La vista previa cambió');
        $this->assertNull($source->fresh()->authority_extraction_confirmed_revision);
        $this->assertFalse($source->fresh()->activo);
        $this->assertSame($before, $preview->fresh()->getAttributes());
        $tokenB = $this->displayedToken($component->html());
        $this->assertNotSame($tokenA, $tokenB);
        $component->call('confirmarPreview', $source->id, $tokenB)->assertHasNoErrors();
        $this->assertSame($source->authority_extraction_revision, $source->fresh()->authority_extraction_confirmed_revision);
        $this->assertFalse($source->fresh()->activo);
        $this->assertDatabaseCount('snapshots', 0);
        $this->assertDatabaseCount('cambios', 0);
    }

    public function test_missing_displayed_token_is_rejected_without_confirmation(): void
    {
        $source = $this->source();
        $this->ready($source);
        Livewire::test(Fuentes::class)->call('confirmarPreview', $source->id)->assertHasErrors(['preview']);
        $this->assertNull($source->fresh()->authority_extraction_confirmed_revision);
        $this->assertFalse($source->fresh()->activo);
    }

    public function test_pending_running_incomplete_and_error_states_are_displayed_without_untrusted_error_text(): void
    {
        $source = $this->source();
        $preview = app(FuenteService::class)->solicitarPreviewAutoridades($source->id);
        Livewire::test(Fuentes::class)->assertSee('Pendiente')->assertSee('wire:poll.15s', false);
        $preview->update(['status' => 'running']);
        Livewire::test(Fuentes::class)->assertSee('En proceso')->assertSee('wire:poll.15s', false);
        $preview->update(['status' => 'incomplete', 'error_code' => 'untrusted <script>']);
        Livewire::test(Fuentes::class)->assertSee('Incompleta')->assertDontSee('wire:poll.', false)
            ->assertDontSee('untrusted');
        $preview->update(['status' => 'error']);
        Livewire::test(Fuentes::class)->assertSee('No se pudo generar la vista previa')
            ->assertDontSee('untrusted')->assertDontSee('wire:poll.', false);
    }

    public function test_fetch_error_shows_safe_consultation_label_without_raw_error_code_or_untrusted_text(): void
    {
        $source = $this->source();
        $preview = app(FuenteService::class)->solicitarPreviewAutoridades($source->id);
        $preview->update(['status' => 'error', 'error_code' => 'fetch_error']);

        Livewire::test(Fuentes::class)->assertSee('No se pudo consultar la fuente')
            ->assertDontSee('fetch_error')->assertDontSee('wire:poll.', false);
        $preview->update(['error_code' => 'untrusted <script>alert(1)</script>']);
        Livewire::test(Fuentes::class)->assertSee('No se pudo generar la vista previa')
            ->assertDontSee('untrusted')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_visible_current_generic_previews_share_one_table_poll_and_stale_or_terminal_previews_do_not_poll(): void
    {
        $first = $this->source();
        $second = $this->source('https://example.test/other-officials');
        $firstPreview = app(FuenteService::class)->solicitarPreviewAutoridades($first->id);
        $secondPreview = app(FuenteService::class)->solicitarPreviewAutoridades($second->id);
        $secondPreview->update(['status' => 'running']);

        $html = Livewire::test(Fuentes::class)->html();
        $this->assertSame(1, substr_count($html, 'wire:poll.15s'));
        $this->assertSame(1, substr_count($html, 'wire:poll.'));

        $firstPreview->update(['status' => 'ready']);
        $secondPreview->update(['source_url' => 'https://example.test/old']);
        $this->assertSame(0, substr_count(Livewire::test(Fuentes::class)->html(), 'wire:poll.'));

        $firstPreview->update(['status' => 'pending', 'source_url' => 'https://example.test/old']);
        $secondPreview->update(['status' => 'error', 'source_url' => $second->url]);
        $this->assertSame(0, substr_count(Livewire::test(Fuentes::class)->html(), 'wire:poll.'));
    }

    public function test_ready_preview_with_invalid_fingerprint_is_hidden_and_rejected_under_lock(): void
    {
        $source = $this->source();
        $preview = $this->ready($source, [['cargo' => 'Private role', 'persona' => 'Private person']]);
        $preview->update(['config_fingerprint' => str_repeat('z', 64)]);

        $this->assertInvalidReadyPreview($source);
    }

    public function test_ready_preview_with_outstanding_lease_is_hidden_and_rejected_under_lock(): void
    {
        $source = $this->source();
        $preview = $this->ready($source, [['cargo' => 'Private role', 'persona' => 'Private person']]);
        $preview->update(['lease_token' => '123e4567-e89b-42d3-a456-426614174000']);

        $this->assertInvalidReadyPreview($source);
    }

    public function test_ready_preview_with_malformed_authorities_is_hidden_and_rejected_under_lock(): void
    {
        $source = $this->source();
        $preview = $this->ready($source);
        foreach ([
            [],
            [['cargo' => ' Private role', 'persona' => 'Private person']],
            [['cargo' => 'Private role', 'persona' => 'Private person', 'extra' => 'invalid']],
            [['cargo' => str_repeat('x', 301), 'persona' => 'Private person']],
            array_fill(0, 201, ['cargo' => 'Private role', 'persona' => 'Private person']),
        ] as $authorities) {
            $preview->update(['authorities_json' => $authorities]);
            $this->assertInvalidReadyPreview($source);
        }
    }

    private function assertInvalidReadyPreview(Fuente $source): void
    {
        Livewire::test(Fuentes::class)->assertSee('No válida')->assertDontSee('Private person')
            ->assertDontSee('Confirmar vista previa')
            ->call('confirmarPreview', $source->id, str_repeat('a', 64))->assertHasErrors(['preview']);

        try {
            app(FuenteService::class)->confirmarPreviewAutoridades($source->id, str_repeat('a', 64));
            $this->fail('The locked confirmation should reject invalid preview data.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'La configuración de extracción de autoridades no es válida.',
                $exception->errors()['authority_extraction_config'][0]
            );
        }
        $this->assertNull($source->fresh()->authority_extraction_confirmed_revision);
    }

    public function test_stale_and_invalid_preview_cannot_be_confirmed_or_display_roster(): void
    {
        $source = $this->source();
        $preview = $this->ready($source, [['cargo' => 'Secret role', 'persona' => 'Old person']]);
        $preview->update(['source_url' => 'https://example.test/old']);
        Livewire::test(Fuentes::class)->assertDontSee('Old person')->assertDontSee('Confirmar vista previa')
            ->call('confirmarPreview', $source->id, str_repeat('a', 64))->assertHasErrors();
        $this->assertNull($source->fresh()->authority_extraction_confirmed_revision);
        $preview->update(['source_url' => $source->url, 'status' => 'incomplete']);
        Livewire::test(Fuentes::class)->assertDontSee('Old person')->assertDontSee('Confirmar vista previa');
    }
}
