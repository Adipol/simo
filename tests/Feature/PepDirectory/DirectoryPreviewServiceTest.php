<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

require_once __DIR__.'/../../Support/PepDirectoryConfigurationTestCase.php';

use App\Exceptions\PepDirectory\{ConfigurationConflictException, PreviewCompilationException};
use App\Models\{PepDirectorySource, PepDirectoryCapture, PepDirectoryPreview, User};
use App\Services\PepDirectory\{DirectoryPreviewService, ConfigurationService, PreviewCompiler};
use App\Services\PepDirectory\DTOs\{CompiledPreviewData, ConfigurationDecisionData, DirectoryMappingData, ServerCaptureData};
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\Support\PepDirectoryConfigurationTestCase;

final class DirectoryPreviewServiceTest extends PepDirectoryConfigurationTestCase
{
    private function actor(): User
    {
        $id = $this->db->table('users')->insertGetId(['label' => 'synthetic lifecycle', 'name' => 'Persisted operator', 'activo' => true]);
        $actor = User::findOrFail($id);
        $actor->givePermissionTo(Permission::firstOrCreate(['name' => 'gestionar fuentes', 'guard_name' => 'web']));
        return $actor;
    }

    private function fuente(): int
    {
        return $this->db->table('fuentes')->insertGetId(['label' => 'synthetic lifecycle', 'url' => 'https://example.invalid/directory', 'tipo' => 'directorio']);
    }

    private function decision(int $version): ConfigurationDecisionData
    {
        return ConfigurationDecisionData::fromArray(['expected_version' => $version, 'reason' => 'Synthetic operator request']);
    }

    private function captureInput(): array
    {
        $request = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PepDirectory/preview-compiler/request.json'), true, 32, JSON_THROW_ON_ERROR);
        return ['raw_body' => base64_decode($request['body_base64'], true), 'status' => 200, 'requested_url' => $request['declaration']['url'], 'final_url' => $request['declaration']['url'], 'captured_at' => '2026-10-18T12:00:00Z', 'encoding' => 'utf-8', 'provenance' => ['adapter' => 'controlled-SYNTHETIC-double']];
    }

    private function mapping(string $record = 'tr'): DirectoryMappingData
    {
        return DirectoryMappingData::fromArray(['directory' => 'table', 'record' => $record, 'columns' => 2, 'fields' => ['unit' => ['constant' => 'Synthetic unit'], 'role' => ['column' => 0], 'person' => ['column' => 1]]]);
    }

    /** A SYNTHETIC compiler double, not Python execution or evidence of extraction correctness. */
    private function compiler(?callable $during = null, bool $empty = false): PreviewCompiler
    {
        $compiler = $this->createMock(PreviewCompiler::class);
        $compiler->method('compile')->willReturnCallback(function (array $d, string $body, array $metadata) use ($during, $empty): CompiledPreviewData {
            self::assertSame(1, $this->db->transactionLevel(), 'Only the test-owned outer transaction remains during compilation.');
            if ($during !== null) {
                $during();
            }
            // Test-only artifact synthesis. There is intentionally no production PHP compiler.
            $fields = $d['fields'];
            ksort($fields);
            $pairs = [];
            foreach ($fields as $key => $field) {
                $values = array_replace(['scope' => 'record', 'selector' => null, 'column' => null, 'constant' => null], $field);
                ksort($values);
                $pairs[] = [$key, $values];
            }
            $config = ['columns' => $d['columns'], 'directory' => $d['directory'], 'fields' => $pairs, 'record' => $d['record'], 'section' => null, 'source' => $d['source'], 'url' => $d['url']];
            $encode = static fn (array $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $canonical = $encode(['config' => $config, 'contract' => 'configured-html-v1']);
            $revision = 'configured-html-v1:'.hash('sha256', $canonical);
            $positions = $empty ? [] : [['unit' => 'Synthetic unit', 'role' => 'Synthetic role', 'person' => 'Synthetic holder', 'condition' => null, 'evidence' => 'Synthetic role Synthetic holder']];
            $wire = ['source' => $d['source'], 'revision' => $revision, 'observed_at' => $metadata['observed_at'], 'validity' => 'incomplete', 'positions' => $positions, 'evidence' => $body];
            $result = $encode($wire);
            $artifact = ['format_version' => 1, 'configuration' => ['declaration' => $d, 'canonical_base64' => base64_encode($canonical), 'revision' => $revision], 'capture' => ['body_base64' => base64_encode($body), 'content_sha256' => hash('sha256', $body), 'metadata' => $metadata], 'preview' => ['wire' => $wire, 'result_base64' => base64_encode($result), 'result_sha256' => hash('sha256', $result), 'validity' => 'incomplete']];
            return CompiledPreviewData::fromOutput($encode($artifact), $d, $body, $metadata);
        });
        return $compiler;
    }

    private function setupLifecycle(?PreviewCompiler $compiler = null): array
    {
        $actor = $this->actor();
        $fuente = $this->fuente();
        $service = new DirectoryPreviewService($compiler ?? $this->compiler());
        $source = $service->register($fuente, $actor);
        $capture = $service->recordCapture($source->id, ServerCaptureData::fromArray($this->captureInput()), $actor, $this->decision(1));
        return [$service, $source->fresh(), $capture, $actor, $fuente];
    }

    private function state(): array
    {
        $state = [];
        foreach (['sources', 'configurations', 'captures', 'previews', 'configuration_decisions', 'observations', 'events', 'reviews'] as $suffix) {
            $rows = $this->db->table('pep_directory_'.$suffix)->orderBy('id')->get()->toArray();
            foreach ($rows as $row) {
                foreach ($row as &$value) {
                    if (is_resource($value)) {
                        rewind($value);
                        $value = stream_get_contents($value);
                    }
                }
            }
            $state[$suffix] = serialize($rows);
        }
        return $state;
    }

    private function rejected(string $exception, callable $operation): void
    {
        $before = $this->state();
        try {
            $operation();
            self::fail('Expected '.$exception);
        } catch (\Throwable $e) {
            self::assertInstanceOf($exception, $e);
        }
        self::assertSame($before, $this->state());
        self::assertSame(1, $this->db->transactionLevel());
    }

    public function test_public_registration_is_server_owned_and_idempotent(): void
    {
        $actor = $this->actor();
        $fuente = $this->fuente();
        $legacy = $this->db->table('pep_directory_sources')->insertGetId(['identity' => 'legacy synthetic', 'fuente_id' => $fuente, 'version' => 9]);
        $legacyBefore = $this->db->table('pep_directory_sources')->find($legacy);
        $service = new DirectoryPreviewService($this->createMock(PreviewCompiler::class));
        $source = $service->register($fuente, $actor);
        self::assertTrue(Str::isUuid($source->identity));
        self::assertTrue($source->configurable);
        self::assertNotSame($legacy, $source->id);
        self::assertSame($source->id, $service->register($fuente, $actor)->id);
        self::assertSame(1, $source->eligibility_version);
        self::assertCount(1, $source->configurationDecisions);
        self::assertSame('register', $source->configurationDecisions[0]->action);
        self::assertEquals($legacyBefore, $this->db->table('pep_directory_sources')->find($legacy));
        $this->rejected(ModelNotFoundException::class, fn () => $service->register(PHP_INT_MAX, $actor));
        $other = $service->register($this->fuente(), $actor);
        self::assertNotSame($source->identity, $other->identity);
        // The UUID factory can force a collision, never an alias/rebind or takeover.
        Str::createUuidsUsing(static fn (): \Ramsey\Uuid\UuidInterface => \Ramsey\Uuid\Uuid::fromString($source->identity));
        try {
            $this->rejected(\Illuminate\Database\QueryException::class, fn () => $service->register($this->fuente(), $actor));
        } finally {
            Str::createUuidsNormally();
        }
    }

    public function test_publication_separate_approval_correction_and_exact_capture_reuse(): void
    {
        $sideEffects = [];
        foreach (['App\\Events\\*', 'eloquent.created: *', 'eloquent.updated: *', 'eloquent.deleted: *'] as $pattern) {
            $this->app['events']->listen($pattern, static function (string $event) use (&$sideEffects): void { $sideEffects[] = $event; });
        }
        [$service, $source, $capture, $actor] = $this->setupLifecycle();
        $sideEffects = []; // Exclude fixture actor permission creation, not lifecycle mutations.
        $baseline = $this->db->table('pep_directory_observations')->insertGetId(['source_id' => $source->id, 'submission_id' => 'synthetic-lifecycle', 'capture_identity' => 'synthetic-lifecycle', 'content_sha256' => $capture->content_sha256, 'provenance' => '{}', 'payload' => '{}', 'observed_at' => $capture->metadata['observed_at'], 'disposition' => 'baseline', 'reasons' => '[]']);
        $this->db->table('pep_directory_sources')->where('id', $source->id)->update(['version' => 7, 'baseline_observation_id' => $baseline]);
        $source = $source->fresh();
        $actor->name = 'Forged caller';
        $actor->setConnection('untrusted');
        $captureBefore = $this->state()['captures'];
        $historyBefore = array_intersect_key($this->state(), array_flip(['observations', 'events', 'reviews']));
        $sourceBefore = $source->getRawOriginal();
        $preview = $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2));
        self::assertSame(3, $preview->eligibility_version);
        self::assertSame('candidate', $preview->qualification['state']);
        self::assertNull($source->fresh()->approved_decision_id);
        $approval = (new ConfigurationService())->approve($source->id, $preview->id, $actor, $this->decision(3));
        self::assertSame($approval->id, $source->fresh()->approved_decision_id);
        $failed = new DirectoryPreviewService($this->compiler(static function (): void { throw new PreviewCompilationException(); }));
        $this->rejected(PreviewCompilationException::class, fn () => $failed->publishDraftPreview($source->id, $capture->id, $this->mapping('tbody tr'), $actor, $this->decision(4)));
        self::assertSame($approval->id, $source->fresh()->approved_decision_id);
        $corrected = $service->publishDraftPreview($source->id, $capture->id, $this->mapping('tbody tr'), $actor, $this->decision(4));
        self::assertSame($capture->id, $corrected->capture_id);
        self::assertNotSame($preview->configuration_id, $corrected->configuration_id);
        self::assertSame(5, $corrected->eligibility_version);
        self::assertNull($source->fresh()->approved_decision_id);
        self::assertSame($corrected->id, $source->fresh()->current_preview_id);
        $again = $service->publishDraftPreview($source->id, $capture->id, $this->mapping('tbody tr'), $actor, $this->decision(5));
        self::assertSame($corrected->configuration_id, $again->configuration_id);
        self::assertSame($captureBefore, $this->state()['captures']);
        self::assertSame($historyBefore, array_intersect_key($this->state(), $historyBefore));
        foreach (['version', 'baseline_observation_id'] as $key) {
            self::assertSame($sourceBefore[$key], $source->fresh()->getRawOriginal($key));
        }
        self::assertSame(['register', 'capture', 'publish', 'approve', 'publish', 'publish'], $source->fresh()->configurationDecisions->pluck('action')->all());
        self::assertSame([1, 2, 3, 4, 5, 6], $source->fresh()->configurationDecisions->pluck('sequence')->all());
        foreach ($source->fresh()->configurationDecisions as $receipt) {
            self::assertSame(['id' => $actor->id, 'name' => 'Persisted operator'], $receipt->actor_snapshot);
        }
        self::assertSame([], $sideEffects, 'No application or Eloquent mutation events; retrieval/query/transaction instrumentation is not suppressed.');
    }

    public function test_reordered_associative_fields_reuse_the_same_immutable_configuration(): void
    {
        $a = $this->mapping();
        $reordered = $a->mapping;
        $reordered['fields'] = array_reverse($reordered['fields'], true);
        $this->assertEquivalentMappingReuse($a, DirectoryMappingData::fromArray($reordered));
    }

    public function test_omitted_and_explicit_record_selector_scope_reuse_the_same_configuration(): void
    {
        $omitted = $this->mapping()->mapping;
        $omitted['fields']['role'] = ['selector' => 'td.role'];
        $explicit = $omitted;
        $explicit['fields']['role']['scope'] = 'record';
        $this->assertEquivalentMappingReuse(DirectoryMappingData::fromArray($omitted), DirectoryMappingData::fromArray($explicit));
    }

    private function assertEquivalentMappingReuse(DirectoryMappingData $a, DirectoryMappingData $b): void
    {
        [$service, $source, $capture, $actor] = $this->setupLifecycle();
        $baseline = $this->db->table('pep_directory_observations')->insertGetId(['source_id' => $source->id, 'submission_id' => 'synthetic-equivalent', 'capture_identity' => 'synthetic-equivalent', 'content_sha256' => $capture->content_sha256, 'provenance' => '{}', 'payload' => '{}', 'observed_at' => $capture->metadata['observed_at'], 'disposition' => 'baseline', 'reasons' => '[]']);
        $this->db->table('pep_directory_sources')->where('id', $source->id)->update(['version' => 7, 'baseline_observation_id' => $baseline]);
        $input = $this->captureInput();
        $compiler = $this->compiler();
        $binding = ['source' => $source->identity, 'url' => $input['final_url']];
        $compiledA = $compiler->compile($binding + $a->mapping, $input['raw_body'], $capture->metadata);
        $compiledB = $compiler->compile($binding + $b->mapping, $input['raw_body'], $capture->metadata);
        self::assertNotSame($a->mapping, $b->mapping);
        self::assertSame($compiledA->canonicalBytes, $compiledB->canonicalBytes, 'The existing synthetic double must really normalize both variants identically.');
        self::assertSame($compiledA->revision, $compiledB->revision);
        $first = $service->publishDraftPreview($source->id, $capture->id, $a, $actor, $this->decision(2));
        $before = $this->state();
        $actorBefore = $actor->getRawOriginal();
        $counts = [];
        foreach (['sources', 'configurations', 'captures', 'previews', 'configuration_decisions', 'observations', 'events', 'reviews'] as $table) {
            $counts[$table] = $this->db->table('pep_directory_'.$table)->count();
        }
        // Public-service RED must reach this operation for each independently executed variant.
        $second = $service->publishDraftPreview($source->id, $capture->id, $b, $actor, $this->decision(3));
        self::assertSame($first->configuration_id, $second->configuration_id);
        self::assertNotSame($first->id, $second->id);
        self::assertSame(4, $second->eligibility_version);
        $approval = (new ConfigurationService())->approve($source->id, $second->id, $actor, $this->decision(4));
        $third = $service->publishDraftPreview($source->id, $capture->id, $a, $actor, $this->decision(5));
        self::assertSame($first->configuration_id, $third->configuration_id);
        self::assertSame(6, $third->eligibility_version);
        $fresh = $source->fresh();
        self::assertNull($fresh->approved_decision_id);
        self::assertSame($third->id, $fresh->current_preview_id);
        self::assertSame($first->configuration_id, $fresh->draft_config_id);
        self::assertSame(7, $fresh->version);
        self::assertSame($baseline, $fresh->baseline_observation_id);
        self::assertSame($actorBefore, $actor->fresh()->getRawOriginal());
        foreach (['configurations', 'captures', 'observations', 'events', 'reviews'] as $table) {
            self::assertSame($before[$table], $this->state()[$table], 'Original immutable '.$table.' rows retained.');
        }
        foreach ($counts as $table => $count) {
            $delta = match ($table) { 'previews' => 2, 'configuration_decisions' => 3, default => 0 };
            self::assertSame($count + $delta, $this->db->table('pep_directory_'.$table)->count(), $table);
        }
        foreach ([$second, $third] as $preview) {
            self::assertSame($capture->id, $preview->capture_id);
        }
        $receipt = $fresh->configurationDecisions->last();
        self::assertSame('publish', $receipt->action);
        self::assertSame(6, $receipt->sequence);
        self::assertSame($third->id, $receipt->preview_id);
        self::assertSame($approval->id, $receipt->prior_approval_id);
        self::assertNull($receipt->new_approval_id);
        self::assertSame(['id' => $actor->id, 'name' => 'Persisted operator'], $receipt->actor_snapshot);
    }

    public function test_capture_bytes_time_urls_attribution_and_generation_receipt(): void
    {
        [$service, $source, $capture, $actor, $fuente] = $this->setupLifecycle();
        $input = $this->captureInput();
        self::assertSame(hash('sha256', $input['raw_body']), $capture->content_sha256);
        self::assertSame($input['raw_body'], stream_get_contents($capture->body_bytes));
        self::assertSame('2026-10-18T12:00:00Z', $capture->provenance['captured_at_input']);
        self::assertSame('2026-10-18T12:00:00+00:00', $capture->metadata['observed_at']);
        self::assertSame('user:'.$actor->id, $capture->provenance['actor_identity']);
        self::assertSame('2026-10-18T12:00:00+00:00', $capture->captured_at->format('c'));
        foreach (['requested_url', 'final_url'] as $key) {
            $bad = ServerCaptureData::fromArray(array_replace($input, [$key => 'https://example.invalid/redirect']));
            $this->rejected(ValidationException::class, fn () => $service->recordCapture($source->id, $bad, $actor, $this->decision(2)));
        }
        [$otherService, $other, , $otherActor] = $this->setupLifecycle();
        $this->rejected(ModelNotFoundException::class, fn () => $otherService->publishDraftPreview($other->id, $capture->id, $this->mapping(), $otherActor, $this->decision(2)));
        $this->rejected(ConfigurationConflictException::class, fn () => $service->recordCapture($source->id, ServerCaptureData::fromArray($input), $actor, $this->decision(1)));
        $this->db->table('fuentes')->where('id', $fuente)->update(['activo' => false]);
        $inactive = $service->recordCapture($source->id, ServerCaptureData::fromArray($input), $actor, $this->decision(3));
        self::assertSame($source->id, $inactive->source_id);
        $preview = $service->publishDraftPreview($source->id, $inactive->id, $this->mapping(), $actor, $this->decision(4));
        $this->rejected(ValidationException::class, fn () => (new ConfigurationService())->approve($source->id, $preview->id, $actor, $this->decision(5)));
    }

    public function test_persisted_actor_is_checked_on_every_mutation_and_both_compile_phases(): void
    {
        [$service, $source, $capture, $actor, $fuente] = $this->setupLifecycle();
        $actor->load('permissions', 'roles');
        $actor->activo = true;
        foreach (['inactive', 'revoked', 'missing'] as $mode) {
            $this->db->beginTransaction();
            try {
                if ($mode === 'inactive') {
                    $this->db->table('users')->where('id', $actor->id)->update(['activo' => false]);
                } elseif ($mode === 'revoked') {
                    $this->db->table('model_has_permissions')->where('model_id', $actor->id)->delete();
                } else {
                    $this->db->table('users')->where('id', $actor->id)->delete();
                }
                // Nested fixture transaction, so don't use rejected()'s level=1 assertion here.
                foreach ([fn () => $service->register($fuente, $actor), fn () => $service->recordCapture($source->id, ServerCaptureData::fromArray($this->captureInput()), $actor, $this->decision(2)), fn () => $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2))] as $operation) {
                    $before = $this->state();
                    try { $operation(); self::fail('Persisted authorization required'); } catch (AuthorizationException) {}
                    self::assertSame($before, $this->state());
                }
            } finally {
                $this->db->rollBack();
            }
        }
        $late = new DirectoryPreviewService($this->compiler(function () use ($actor): void {
            $this->db->table('model_has_permissions')->where('model_id', $actor->id)->delete();
        }));
        $this->rejected(AuthorizationException::class, fn () => $late->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2)));
    }

    public function test_stale_compile_snapshots_never_substitute_current_state(): void
    {
        foreach (['generation', 'binding', 'draft', 'configuration', 'capture', 'preview'] as $mode) {
            [$service, $source, $capture, $actor, $fuente] = $this->setupLifecycle();
            $initial = $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2));
            $afterConcurrent = null;
            $late = new DirectoryPreviewService($this->compiler(function () use ($mode, $source, $capture, $fuente, $initial, &$afterConcurrent): void {
                match ($mode) {
                    'generation' => $this->db->table('pep_directory_sources')->where('id', $source->id)->increment('eligibility_version'),
                    'binding' => $this->db->table('fuentes')->where('id', $fuente)->update(['url' => 'https://example.invalid/changed']),
                    'draft' => $this->db->table('pep_directory_sources')->where('id', $source->id)->update(['draft_config_id' => null]),
                    'configuration' => $this->db->table('pep_directory_configurations')->where('id', $initial->configuration_id)->update(['canonical_bytes' => 'corrupted']),
                    'capture' => $this->db->table('pep_directory_captures')->where('id', $capture->id)->update(['body_bytes' => 'corrupted']),
                    'preview' => $this->db->table('pep_directory_previews')->where('id', $initial->id)->update(['result_bytes' => 'corrupted']),
                };
                $afterConcurrent = $this->state();
            }));
            try {
                $late->publishDraftPreview($source->id, $capture->id, $this->mapping('tbody tr'), $actor, $this->decision(3));
                self::fail('Stale snapshot accepted: '.$mode);
            } catch (ConfigurationConflictException) {}
            self::assertSame($afterConcurrent, $this->state(), $mode.' has no additional publication effects');
        }
    }

    public function test_insert_and_projection_faults_roll_back_all_publication_writes(): void
    {
        [$service, $source, $capture, $actor] = $this->setupLifecycle();
        foreach (['insert into "pep_directory_configurations"', 'insert into "pep_directory_previews"', 'insert into "pep_directory_configuration_decisions"', 'update "pep_directory_sources"'] as $needle) {
            $fault = (object) ['armed' => true];
            $this->db->beforeExecuting(static function (string $sql) use ($fault, $needle): void {
                if ($fault->armed && str_starts_with($sql, $needle)) {
                    $fault->armed = false;
                    throw new \RuntimeException('Synthetic atomic fault');
                }
            });
            $this->rejected(\RuntimeException::class, fn () => $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2)));
        }
        $published = $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2));
        $this->db->table('pep_directory_configurations')->where('id', $published->configuration_id)->update(['canonical_bytes' => 'same revision WRONG bytes']);
        $this->rejected(ValidationException::class, fn () => $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(3)));
    }

    public function test_diagnostics_are_stored_but_cannot_be_approved_and_actual_artifact_is_separate(): void
    {
        [$service, $source, $capture, $actor] = $this->setupLifecycle($this->compiler(null, true));
        $preview = $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2));
        self::assertSame('unapprovable', $preview->qualification['state']);
        $this->rejected(ValidationException::class, fn () => (new ConfigurationService())->approve($source->id, $preview->id, $actor, $this->decision(3)));
        // Retained actual Python artifact compatibility ONLY, not the dynamic fake's producer proof.
        $output = file_get_contents(__DIR__.'/../../Fixtures/PepDirectory/preview-compiler/artifact.json');
        $a = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $compiled = CompiledPreviewData::fromOutput($output, $a['configuration']['declaration'], base64_decode($a['capture']['body_base64'], true), $a['capture']['metadata']);
        self::assertSame(base64_decode($a['preview']['result_base64'], true), $compiled->resultBytes);
    }

    public function test_registration_and_capture_faults_and_binding_discovery_preserve_atomicity(): void
    {
        $actor = $this->actor();
        $fuente = $this->fuente();
        $compiler = $this->createMock(PreviewCompiler::class);
        $compiler->expects(self::never())->method('compile');
        $service = new DirectoryPreviewService($compiler);
        foreach (['insert into "pep_directory_configuration_decisions"', 'update "pep_directory_sources"'] as $needle) {
            $fault = (object) ['armed' => true];
            $this->db->beforeExecuting(static function (string $sql) use ($fault, $needle): void {
                if ($fault->armed && str_starts_with($sql, $needle)) {
                    $fault->armed = false;
                    throw new \RuntimeException('Synthetic registration fault');
                }
            });
            $this->rejected(\RuntimeException::class, fn () => $service->register($fuente, $actor));
        }
        $this->db->table('fuentes')->where('id', $fuente)->update(['activo' => false]);
        $source = $service->register($fuente, $actor);
        foreach (['insert into "pep_directory_captures"', 'insert into "pep_directory_configuration_decisions"', 'update "pep_directory_sources"'] as $needle) {
            $fault = (object) ['armed' => true];
            $this->db->beforeExecuting(static function (string $sql) use ($fault, $needle): void {
                if ($fault->armed && str_starts_with($sql, $needle)) {
                    $fault->armed = false;
                    throw new \RuntimeException('Synthetic capture fault');
                }
            });
            $this->rejected(\RuntimeException::class, fn () => $service->recordCapture($source->id, ServerCaptureData::fromArray($this->captureInput()), $actor, $this->decision(1)));
        }
        $locks = [];
        $this->db->listen(static function (\Illuminate\Database\Events\QueryExecuted $query) use (&$locks): void {
            if (str_contains($query->sql, 'for update')) { $locks[] = $query->sql; }
        });
        $service->recordCapture($source->id, ServerCaptureData::fromArray($this->captureInput()), $actor, $this->decision(1));
        self::assertCount(3, $locks);
        foreach (['fuentes', 'pep_directory_sources', 'users'] as $index => $table) {
            self::assertStringContainsString('"'.$table.'"', $locks[$index]);
        }
        $other = $this->fuente();
        $fault = (object) ['armed' => true];
        $this->db->beforeExecuting(function (string $sql) use ($fault, $source, $other): void {
            if ($fault->armed && str_starts_with($sql, 'select * from "pep_directory_sources"') && str_contains($sql, 'for update')) {
                $fault->armed = false;
                $this->db->table('pep_directory_sources')->where('id', $source->id)->update(['fuente_id' => $other]);
            }
        });
        $locks = [];
        $this->rejected(ConfigurationConflictException::class, fn () => $service->recordCapture($source->id, ServerCaptureData::fromArray($this->captureInput()), $actor, $this->decision(2)));
        self::assertCount(2, $locks, 'No new Fuente or actor lock after binding changed.');
    }

    public function test_binary_capture_retains_nul_backslash_and_non_utf8_original_bytes(): void
    {
        [$service, $source, , $actor] = $this->setupLifecycle();
        $body = "prefix\0\\bytes\xffsuffix";
        $input = array_replace($this->captureInput(), ['raw_body' => $body, 'encoding' => 'iso-8859-1', 'captured_at' => '2026-10-18T13:02:03.123456+01:00']);
        $capture = $service->recordCapture($source->id, ServerCaptureData::fromArray($input), $actor, $this->decision(2));
        self::assertSame($body, stream_get_contents($capture->body_bytes));
        self::assertSame(hash('sha256', $body), $capture->content_sha256);
        self::assertSame($input['captured_at'], $capture->metadata['observed_at']);
        self::assertSame('2026-10-18T12:02:03.123456+00:00', $capture->captured_at->format('Y-m-d\\TH:i:s.uP'));
    }

    public function test_publication_keeps_exact_compiler_bytes_with_json_backslash_escapes(): void
    {
        [$service, $source, , $actor] = $this->setupLifecycle();
        $input = array_replace($this->captureInput(), ['raw_body' => '<table>Unicode: á; literal \\u1234</table>']);
        $capture = $service->recordCapture($source->id, ServerCaptureData::fromArray($input), $actor, $this->decision(2));
        $m = $this->mapping()->mapping;
        $m['fields']['unit']['constant'] = 'Synthetic unít';
        $preview = $service->publishDraftPreview($source->id, $capture->id, DirectoryMappingData::fromArray($m), $actor, $this->decision(3));
        $canonical = stream_get_contents($preview->configuration->canonical_bytes);
        self::assertStringContainsString('\\u00ed', $canonical);
        self::assertSame('configured-html-v1:'.hash('sha256', $canonical), $preview->configuration->revision);
        $result = stream_get_contents($preview->result_bytes);
        self::assertStringContainsString('\\u00e1', $result);
        self::assertSame(hash('sha256', $result), $preview->result_sha256);
        self::assertSame($input['raw_body'], $preview->wire_payload['evidence']);
    }

    public function test_capture_append_fences_work_without_implicitly_revoking_existing_approval(): void
    {
        [$service, $source, $capture, $actor] = $this->setupLifecycle();
        $preview = $service->publishDraftPreview($source->id, $capture->id, $this->mapping(), $actor, $this->decision(2));
        $approval = (new ConfigurationService())->approve($source->id, $preview->id, $actor, $this->decision(3));
        $next = $service->recordCapture($source->id, ServerCaptureData::fromArray($this->captureInput()), $actor, $this->decision(4));
        self::assertNotSame($capture->id, $next->id);
        self::assertSame(5, $source->fresh()->eligibility_version);
        self::assertSame($approval->id, $source->fresh()->approved_decision_id);
        self::assertSame($preview->id, $source->fresh()->current_preview_id);
        $receipt = $source->fresh()->configurationDecisions->last();
        self::assertSame($approval->id, $receipt->prior_approval_id);
        self::assertSame($approval->id, $receipt->new_approval_id);
        $this->rejected(ConfigurationConflictException::class, fn () => $service->publishDraftPreview($source->id, $next->id, $this->mapping(), $actor, $this->decision(4)));
    }

    public function test_input_boundaries_reject_authority_and_unbounded_or_malformed_fields(): void
    {
        $input = $this->captureInput();
        foreach ([['actor' => 1], ['approved' => true], ['provenance' => ['adapter' => 'fake', 'trusted' => true]], ['captured_at' => '2026-10-18T12:00:00'], ['captured_at' => '2026-02-30T12:00:00Z'], ['status' => '200'], ['status' => 600], ['raw_body' => str_repeat('a', 1048577)], ['encoding' => 'x y'], ['final_url' => 'file:///etc/passwd']] as $change) {
            $this->rejected(ValidationException::class, fn () => ServerCaptureData::fromArray(array_replace($input, $change)));
        }
        foreach (['source', 'url', 'hash', 'approval', 'provenance', 'actor'] as $key) {
            $this->rejected(ValidationException::class, fn () => DirectoryMappingData::fromArray($this->mapping()->mapping + [$key => 'forged']));
        }
        foreach ([['record' => str_repeat('a', 513)], ['columns' => 33], ['fields' => ['unit' => ['constant' => 'u'], 'role' => ['column' => -1], 'person' => ['column' => 1]]]] as $change) {
            $this->rejected(ValidationException::class, fn () => DirectoryMappingData::fromArray(array_replace($this->mapping()->mapping, $change)));
        }
    }
}
