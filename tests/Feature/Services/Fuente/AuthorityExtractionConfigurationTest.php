<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Fuente;

use App\Enums\AuthorityRemovalReviewStatus;
use App\Models\AuthorityExtractionPreview;
use App\Models\AuthorityRemovalReview;
use App\Models\Fuente;
use App\Services\Fuente\FuenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AuthorityExtractionConfigurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_generic_source_create_is_inactive_and_receives_an_unconfirmed_revision(): void
    {
        $fuente = $this->service()->crear($this->genericData(['activo' => true]));

        $this->assertFalse($fuente->activo);
        $this->assertNotNull($fuente->authority_extraction_revision);
        $this->assertNull($fuente->authority_extraction_confirmed_revision);
        $this->assertSame($this->config(), $fuente->authority_extraction_config);
    }

    public function test_generic_source_cannot_be_enabled_until_its_current_revision_is_confirmed(): void
    {
        $fuente = $this->service()->crear($this->genericData());

        $this->assertFalse($this->service()->toggleActivo($fuente->id)->activo);

        $fuente->update(['authority_extraction_confirmed_revision' => $fuente->authority_extraction_revision]);

        $this->assertTrue($this->service()->toggleActivo($fuente->id)->activo);
    }

    public function test_forged_trusted_revision_metadata_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->service()->crear($this->genericData([
            'authority_extraction_revision' => '11111111-1111-4111-8111-111111111111',
        ]));
    }

    /**
     * @dataProvider identityChangeProvider
     *
     * @param  array<string, mixed>  $change
     */
    public function test_identity_changes_rotate_revision_clear_confirmation_and_supersede_pending_reviews(array $change): void
    {
        $fuente = $this->confirmedGenericFuente();
        $pending = $this->pendingReview($fuente);
        $terminal = $this->pendingReview($fuente, AuthorityRemovalReviewStatus::Confirmed);
        $previousRevision = $fuente->authority_extraction_revision;

        $updated = $this->service()->actualizar($fuente->id, array_replace($this->genericData(), $change));

        $this->assertNotSame($previousRevision, $updated->authority_extraction_revision);
        $this->assertNull($updated->authority_extraction_confirmed_revision);
        $this->assertFalse($updated->activo);
        $this->assertSame(AuthorityRemovalReviewStatus::Superseded, $pending->fresh()->estado);
        $this->assertSame(AuthorityRemovalReviewStatus::Confirmed, $terminal->fresh()->estado);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function identityChangeProvider(): array
    {
        return [
            'url' => [['url' => 'https://example.test/changed-url']],
            'type' => [[
                'tipo' => 'pdf',
                'autoridades_extractor' => null,
                'authority_extraction_config' => null,
            ]],
            'extractor' => [[
                'autoridades_extractor' => 'divi_blurb',
                'authority_extraction_config' => null,
            ]],
            'effective config' => [['authority_extraction_config' => array_replace(self::config(), ['max_rows' => 12])]],
        ];
    }

    public function test_noop_default_omission_key_order_and_display_name_update_preserve_confirmation(): void
    {
        $fuente = $this->confirmedGenericFuente();
        $revision = $fuente->authority_extraction_revision;

        $updated = $this->service()->actualizar($fuente->id, $this->genericData([
            'activo' => true,
            'nombre' => 'Renamed only',
            'authority_extraction_config' => [
                'role_selector' => '.role',
                'person_selector' => '.person',
                'row_selector' => 'article',
                'container_selector' => 'main',
                'version' => 1,
            ],
        ]));

        $this->assertSame($revision, $updated->authority_extraction_revision);
        $this->assertSame($revision, $updated->authority_extraction_confirmed_revision);
        $this->assertTrue($updated->activo);
    }

    public function test_pdf_generic_configuration_is_rejected_without_changing_the_source(): void
    {
        $fuente = $this->confirmedGenericFuente();

        try {
            $this->service()->actualizar($fuente->id, $this->genericData(['tipo' => 'pdf']));
            $this->fail('Expected generic PDF configuration validation to fail.');
        } catch (ValidationException) {
            $this->assertSame('html', $fuente->fresh()->tipo);
            $this->assertSame('structured_css_v1', $fuente->fresh()->autoridades_extractor);
        }
    }

    public function test_partial_metadata_update_preserves_an_active_confirmed_generic_source(): void
    {
        $fuente = $this->confirmedGenericFuente();

        $updated = $this->service()->actualizar($fuente->id, ['nombre' => 'Metadata only']);

        $this->assertTrue($updated->activo);
        $this->assertSame($fuente->authority_extraction_revision, $updated->authority_extraction_revision);
    }

    public function test_explicit_deactivation_of_confirmed_generic_source_is_preserved(): void
    {
        $fuente = $this->confirmedGenericFuente();

        $updated = $this->service()->actualizar($fuente->id, ['activo' => false]);

        $this->assertFalse($updated->activo);
    }

    public function test_switching_away_invalidates_pending_reviews_but_preserves_other_source_behavior(): void
    {
        $generic = $this->confirmedGenericFuente();
        $pending = $this->pendingReview($generic);
        $ordinary = Fuente::factory()->create(['url' => 'https://example.test/ordinary']);

        $updated = $this->service()->actualizar($generic->id, $this->genericData([
            'autoridades_extractor' => null,
            'authority_extraction_config' => null,
        ]));

        $this->assertNull($updated->authority_extraction_config);
        $this->assertSame(AuthorityRemovalReviewStatus::Superseded, $pending->fresh()->estado);
        $this->assertSame($pending->id, $pending->fresh()->lifecycle_key);
        $this->assertFalse($this->service()->toggleActivo($ordinary->id)->activo);
    }

    public function test_invalid_config_rolls_back_source_and_pending_review_changes(): void
    {
        $fuente = $this->confirmedGenericFuente();
        $pending = $this->pendingReview($fuente);
        $revision = $fuente->authority_extraction_revision;

        try {
            $this->service()->actualizar($fuente->id, $this->genericData([
                'url' => 'https://example.test/would-change',
                'authority_extraction_config' => array_replace($this->config(), ['min_rows' => true]),
            ]));
            $this->fail('Expected configuration validation to fail.');
        } catch (ValidationException) {
            $this->assertSame('https://example.test/generic', $fuente->fresh()->url);
            $this->assertSame($revision, $fuente->fresh()->authority_extraction_revision);
            $this->assertSame(AuthorityRemovalReviewStatus::Pending, $pending->fresh()->estado);
        }
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('identityChangeProvider')]
    public function test_identity_change_stales_and_clears_each_preview_state(array $change): void
    {
        foreach (['pending', 'running', 'ready'] as $status) {
            $fuente = $this->confirmedGenericFuente(['url' => 'https://example.test/generic-'.$status]);
            $preview = $this->preview($fuente, $status);
            $oldRevision = $preview->source_revision;
            $identity = array_replace($this->genericData(['url' => $fuente->url]), $change);
            if (array_key_exists('url', $change)) {
                $identity['url'] .= '-'.$status;
            }

            $updated = $this->service()->actualizar($fuente->id, $identity);
            $stale = $preview->fresh();

            $this->assertSame('stale', $stale->status);
            $this->assertSame($oldRevision, $stale->source_revision);
            $this->assertNotSame($updated->authority_extraction_revision, $stale->source_revision);
            $this->assertNull($stale->authorities_json);
            $this->assertNull($stale->config_fingerprint);
            $this->assertNull($stale->error_code);
            $this->assertNull($stale->lease_token);
            $this->assertNull($stale->lease_expires_at);
            $this->assertNull($stale->completed_at);
        }
    }

    public function test_metadata_noop_default_equivalence_and_activation_do_not_invalidate_preview(): void
    {
        $fuente = $this->confirmedGenericFuente();
        $preview = $this->preview($fuente, 'ready');
        $revision = $fuente->authority_extraction_revision;

        $this->service()->actualizar($fuente->id, ['nombre' => 'Updated display']);
        $this->service()->actualizar($fuente->id, [
            'authority_extraction_config' => [
                'role_selector' => '.role', 'person_selector' => '.person',
                'row_selector' => 'article', 'container_selector' => 'main', 'version' => 1,
            ],
        ]);
        $this->service()->actualizar($fuente->id, []);
        $this->service()->actualizar($fuente->id, ['activo' => false]);

        $this->assertSame($revision, $fuente->fresh()->authority_extraction_revision);
        $this->assertSame('ready', $preview->fresh()->status);
        $this->assertSame([['person' => 'Ada', 'role' => 'Director']], $preview->fresh()->authorities_json);
        $this->assertSame(str_repeat('a', 64), $preview->fresh()->config_fingerprint);
        $this->assertNotNull($preview->fresh()->completed_at);
    }

    public function test_invalid_source_edit_rolls_back_without_changing_preview_or_source(): void
    {
        $fuente = $this->confirmedGenericFuente();
        $preview = $this->preview($fuente, 'running');
        $revision = $fuente->authority_extraction_revision;

        try {
            $this->service()->actualizar($fuente->id, [
                'url' => 'https://example.test/new',
                'authority_extraction_config' => array_replace(self::config(), ['min_rows' => true]),
            ]);
            $this->fail('Expected invalid configuration to fail.');
        } catch (ValidationException) {
            $this->assertSame($revision, $fuente->fresh()->authority_extraction_revision);
            $this->assertSame('running', $preview->fresh()->status);
            $this->assertNotNull($preview->fresh()->lease_token);
            $this->assertSame('https://example.test/generic', $fuente->fresh()->url);
        }
    }

    public function test_request_creates_one_pending_preview_and_rerequest_revokes_running_lease_without_side_effects(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        $first = $this->service()->solicitarPreviewAutoridades($fuente->id);

        $this->assertSame('pending', $first->status);
        $this->assertSame(0, $first->attempts);
        $this->assertSame($fuente->authority_extraction_revision, $first->source_revision);
        $this->assertSame($fuente->url, $first->source_url);
        $this->assertNotNull($first->requested_at);
        $first->update([
            'status' => 'running', 'attempts' => 2,
            'lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->addHour(), 'completed_at' => now(),
            'config_fingerprint' => str_repeat('a', 64),
            'authorities_json' => [['cargo' => 'Rector', 'persona' => 'Ada']],
            'error_code' => 'incomplete',
        ]);
        $second = $this->service()->solicitarPreviewAutoridades($fuente->id);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AuthorityExtractionPreview::where('fuente_id', $fuente->id)->count());
        $this->assertSame('pending', $second->status);
        $this->assertSame(0, $second->attempts);
        foreach (['lease_token', 'lease_expires_at', 'completed_at', 'config_fingerprint', 'authorities_json', 'error_code'] as $field) {
            $this->assertNull($second->$field);
        }
        $this->assertNull($fuente->fresh()->authority_extraction_confirmed_revision);
        $this->assertFalse($fuente->fresh()->activo);
        $this->assertSame(0, $fuente->snapshots()->count());
        $this->assertSame(0, $fuente->cambios()->count());
    }

    public function test_request_rejects_non_generic_invalid_config_and_missing_revision_without_preview(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        foreach ([
            ['tipo' => 'pdf'],
            ['authority_extraction_config' => ['version' => 1]],
            ['authority_extraction_revision' => null],
        ] as $change) {
            $fuente->update($change);
            try {
                $this->service()->solicitarPreviewAutoridades($fuente->id);
                $this->fail('Invalid source must be rejected.');
            } catch (ValidationException) {
                $this->assertSame(0, AuthorityExtractionPreview::where('fuente_id', $fuente->id)->count());
            }
            $fuente->refresh()->update($this->genericData([
                'authority_extraction_revision' => $fuente->authority_extraction_revision ?? '11111111-1111-4111-8111-111111111111',
            ]));
        }
    }

    public function test_confirmation_rejects_displayed_roster_after_same_revision_rerequest(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        $preview = $this->readyPreview($fuente);
        $displayedToken = $this->service()->previewResultIdentity($fuente, $preview);
        $this->service()->solicitarPreviewAutoridades($fuente->id);
        $preview->refresh()->update([
            'status' => 'ready', 'completed_at' => now(),
            'config_fingerprint' => str_repeat('a', 64),
            'authorities_json' => [['cargo' => 'Rector', 'persona' => 'Beatriz']],
        ]);
        $before = $preview->fresh()->getAttributes();

        try {
            $this->service()->confirmarPreviewAutoridades($fuente->id, $displayedToken);
            $this->fail('The displayed old roster must not confirm the replacement.');
        } catch (ValidationException) {
            $this->assertNull($fuente->fresh()->authority_extraction_confirmed_revision);
            $this->assertFalse($fuente->fresh()->activo);
            $this->assertSame($before, $preview->fresh()->getAttributes());
            $this->assertSame(0, $fuente->snapshots()->count());
            $this->assertSame(0, $fuente->cambios()->count());
        }
    }

    public function test_confirmation_rejects_missing_malformed_and_reordered_result_identity(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        $preview = $this->readyPreview($fuente);
        $preview->update(['authorities_json' => [
            ['cargo' => 'Rector', 'persona' => 'Ada'],
            ['cargo' => 'Secretaria', 'persona' => 'Beatriz'],
        ]]);
        $displayedToken = $this->service()->previewResultIdentity($fuente, $preview);
        $preview->update(['authorities_json' => array_reverse($preview->authorities_json)]);
        $before = $preview->fresh()->getAttributes();
        foreach ([null, '', 'invalid', str_repeat('A', 64), $displayedToken] as $token) {
            try {
                $this->service()->confirmarPreviewAutoridades($fuente->id, $token);
                $this->fail('Missing, malformed or stale identity must be rejected.');
            } catch (ValidationException) {
                $this->assertNull($fuente->fresh()->authority_extraction_confirmed_revision);
                $this->assertFalse($fuente->fresh()->activo);
                $this->assertSame($before, $preview->fresh()->getAttributes());
            }
        }
    }

    public function test_identical_rerequest_preserves_content_identity_but_fingerprint_change_invalidates_it(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        $preview = $this->readyPreview($fuente);
        $token = $this->service()->previewResultIdentity($fuente, $preview);
        $this->service()->solicitarPreviewAutoridades($fuente->id);
        $preview->refresh()->update([
            'status' => 'ready', 'completed_at' => now()->addSecond(), 'attempts' => 2,
            'config_fingerprint' => str_repeat('a', 64),
            'authorities_json' => [['persona' => 'Ada', 'cargo' => 'Rector']],
        ]);
        $this->assertSame($token, $this->service()->previewResultIdentity($fuente, $preview));
        $preview->update(['config_fingerprint' => str_repeat('b', 64)]);
        $this->assertNotSame($token, $this->service()->previewResultIdentity($fuente, $preview));
        try {
            $this->service()->confirmarPreviewAutoridades($fuente->id, $token);
            $this->fail('Changed fingerprint must reject the displayed identity.');
        } catch (ValidationException) {
            $this->assertNull($fuente->fresh()->authority_extraction_confirmed_revision);
        }
        $preview->update(['config_fingerprint' => str_repeat('a', 64)]);
        $confirmed = $this->service()->confirmarPreviewAutoridades($fuente->id, $token);
        $this->assertSame($fuente->authority_extraction_revision, $confirmed->authority_extraction_confirmed_revision);
        $this->assertFalse($confirmed->activo);
    }

    public function test_displayed_identity_is_bound_to_source_and_effective_configuration(): void
    {
        $first = $this->service()->crear($this->genericData());
        $second = $this->service()->crear($this->genericData(['url' => 'https://example.test/other']));
        $firstPreview = $this->readyPreview($first);
        $secondPreview = $this->readyPreview($second);
        $token = $this->service()->previewResultIdentity($first, $firstPreview);
        $this->assertNotSame($token, $this->service()->previewResultIdentity($second, $secondPreview));
        $first->update(['authority_extraction_config' => array_replace($this->config(), ['max_rows' => 12])]);
        $this->assertNotSame($token, $this->service()->previewResultIdentity($first, $firstPreview));
        foreach ([$first, $second] as $source) {
            try {
                $this->service()->confirmarPreviewAutoridades($source->id, $token);
                $this->fail('A different source or configuration must reject the displayed identity.');
            } catch (ValidationException) {
                $this->assertNull($source->fresh()->authority_extraction_confirmed_revision);
                $this->assertFalse($source->fresh()->activo);
            }
        }
    }

    public function test_confirmation_accepts_only_current_python_ready_roster_without_activating(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        $preview = $this->readyPreview($fuente);
        $confirmed = $this->service()->confirmarPreviewAutoridades(
            $fuente->id, $this->service()->previewResultIdentity($fuente, $preview)
        );

        $this->assertSame($fuente->authority_extraction_revision, $confirmed->authority_extraction_confirmed_revision);
        $this->assertFalse($confirmed->activo);
        $this->assertSame('ready', $preview->fresh()->status);
        $this->assertSame(0, $fuente->snapshots()->count());
        $this->assertSame(0, $fuente->cambios()->count());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function invalidReadyPreviewProvider(): array
    {
        return [
            'pending' => [['status' => 'pending']],
            'running' => [['status' => 'running']],
            'incomplete' => [['status' => 'incomplete']],
            'error' => [['status' => 'error']],
            'stale' => [['status' => 'stale']],
            'revision' => [['source_revision' => '11111111-1111-4111-8111-111111111111']],
            'url' => [['source_url' => 'https://example.test/old']],
            'no completion' => [['completed_at' => null]],
            'error code' => [['error_code' => 'incomplete']],
            'lease token' => [['lease_token' => '11111111-1111-4111-8111-111111111111']],
            'lease expiry' => [['lease_expires_at' => '2030-01-01 00:00:00']],
            'bad fingerprint' => [['config_fingerprint' => str_repeat('A', 64)]],
            'short fingerprint' => [['config_fingerprint' => 'abc']],
            'empty roster' => [['authorities_json' => []]],
            'wrong keys' => [['authorities_json' => [['role' => 'Rector', 'person' => 'Ada']]]],
            'extra key' => [['authorities_json' => [['cargo' => 'Rector', 'persona' => 'Ada', 'id' => 1]]]],
            'blank field' => [['authorities_json' => [['cargo' => ' ', 'persona' => 'Ada']]]],
            'untrimmed' => [['authorities_json' => [['cargo' => ' Rector', 'persona' => 'Ada']]]],
            'non string' => [['authorities_json' => [['cargo' => 10, 'persona' => 'Ada']]]],
            'too long' => [['authorities_json' => [['cargo' => str_repeat('x', 301), 'persona' => 'Ada']]]],
            'too many' => [['authorities_json' => array_fill(0, 201, ['cargo' => 'Rector', 'persona' => 'Ada'])]],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('invalidReadyPreviewProvider')]
    public function test_confirmation_rejects_invalid_or_stale_preview_without_mutation(array $change): void
    {
        $fuente = $this->service()->crear($this->genericData());
        $preview = $this->readyPreview($fuente);
        $preview->update($change);
        $before = $preview->fresh()->getAttributes();
        try {
            $this->service()->confirmarPreviewAutoridades($fuente->id, str_repeat('a', 64));
            $this->fail('Invalid preview must be rejected.');
        } catch (ValidationException) {
            $this->assertNull($fuente->fresh()->authority_extraction_confirmed_revision);
            $this->assertFalse($fuente->fresh()->activo);
            $this->assertSame($before, $preview->fresh()->getAttributes());
        }
    }

    public function test_confirmation_requires_a_preview_and_current_generic_html_source(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        foreach ([[], ['tipo' => 'pdf'], ['autoridades_extractor' => null], ['authority_extraction_config' => null]] as $change) {
            $fuente->update($change);
            try {
                $this->service()->confirmarPreviewAutoridades($fuente->id, str_repeat('a', 64));
                $this->fail('Confirmation must reject absent preview.');
            } catch (ValidationException) {
                $this->assertNull($fuente->fresh()->authority_extraction_confirmed_revision);
            }
        }
    }

    public function test_confirmation_accepts_the_maximum_bounded_python_roster(): void
    {
        $fuente = $this->service()->crear($this->genericData());
        $preview = $this->readyPreview($fuente);
        $preview->update(['authorities_json' => array_fill(0, 200, [
            'cargo' => str_repeat('R', 300), 'persona' => str_repeat('P', 300),
        ])]);

        $confirmed = $this->service()->confirmarPreviewAutoridades(
            $fuente->id, $this->service()->previewResultIdentity($fuente, $preview->fresh())
        );

        $this->assertSame($fuente->authority_extraction_revision, $confirmed->authority_extraction_confirmed_revision);
        $this->assertFalse($confirmed->activo);
    }

    public function test_confirmation_rejects_ready_row_when_source_identity_changes(): void
    {
        foreach ([
            ['tipo' => 'pdf'],
            ['autoridades_extractor' => null],
            ['authority_extraction_config' => ['version' => 1]],
            ['authority_extraction_revision' => '22222222-2222-4222-8222-222222222222'],
            ['url' => 'https://example.test/changed'],
        ] as $change) {
            $fuente = $this->service()->crear($this->genericData(['url' => 'https://example.test/source-'.uniqid()]));
            $preview = $this->readyPreview($fuente);
            $fuente->update($change);
            $before = $preview->fresh()->getAttributes();

            try {
                $this->service()->confirmarPreviewAutoridades($fuente->id, str_repeat('a', 64));
                $this->fail('Changed source must reject ready preview.');
            } catch (ValidationException) {
                $this->assertNull($fuente->fresh()->authority_extraction_confirmed_revision);
                $this->assertSame($before, $preview->fresh()->getAttributes());
            }
        }
    }

    private function readyPreview(Fuente $fuente): AuthorityExtractionPreview
    {
        return AuthorityExtractionPreview::create([
            'fuente_id' => $fuente->id,
            'source_revision' => $fuente->authority_extraction_revision,
            'source_url' => $fuente->url,
            'status' => 'ready',
            'attempts' => 1,
            'requested_at' => now(),
            'completed_at' => now(),
            'config_fingerprint' => str_repeat('a', 64),
            'authorities_json' => [['cargo' => 'Rector', 'persona' => 'Ada']],
        ]);
    }

    private function preview(Fuente $fuente, string $status): AuthorityExtractionPreview
    {
        return AuthorityExtractionPreview::create([
            'fuente_id' => $fuente->id,
            'source_revision' => $fuente->authority_extraction_revision,
            'source_url' => $fuente->url,
            'status' => $status,
            'attempts' => 1,
            'lease_token' => '11111111-1111-4111-8111-111111111111',
            'lease_expires_at' => now()->addMinute(),
            'config_fingerprint' => str_repeat('a', 64),
            'authorities_json' => [['person' => 'Ada', 'role' => 'Director']],
            'error_code' => 'incomplete',
            'requested_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function service(): FuenteService
    {
        return app(FuenteService::class);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function genericData(array $overrides = []): array
    {
        return array_replace([
            'url' => 'https://example.test/generic',
            'nombre' => 'Generic authority source',
            'pais' => 'BO',
            'organismo' => 'Test body',
            'nivel' => 'nacional',
            'tipo' => 'html',
            'activo' => false,
            'selector_css' => '',
            'autoridades_extractor' => 'structured_css_v1',
            'authority_extraction_config' => self::config(),
            'analizar_imagenes' => false,
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        return [
            'version' => 1,
            'container_selector' => 'main',
            'row_selector' => 'article',
            'person_selector' => '.person',
            'role_selector' => '.role',
            'min_rows' => 1,
            'max_rows' => 200,
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function confirmedGenericFuente(array $overrides = []): Fuente
    {
        $fuente = $this->service()->crear($this->genericData($overrides));
        $fuente->update([
            'authority_extraction_confirmed_revision' => $fuente->authority_extraction_revision,
            'activo' => true,
        ]);

        return $fuente->fresh();
    }

    private function pendingReview(Fuente $fuente, AuthorityRemovalReviewStatus $status = AuthorityRemovalReviewStatus::Pending): AuthorityRemovalReview
    {
        return AuthorityRemovalReview::create([
            'fuente_id' => $fuente->id,
            'origen' => 'test',
            'version_esquema' => 1,
            'linea_base_json' => [],
            'candidato_json' => [],
            'eventos_propuestos_json' => [],
            'evidencia_json' => ['version' => 1],
            'fingerprint' => hash('sha256', uniqid((string) $fuente->id, true)),
            'estado' => $status,
        ]);
    }
}
