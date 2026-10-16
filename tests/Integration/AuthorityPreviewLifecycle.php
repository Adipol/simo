<?php

declare(strict_types=1);

// Standalone CLI integration probe: each invocation boots a fresh Laravel process.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\AuthorityExtractionPreview;
use App\Models\Fuente;
use App\Services\Fuente\FuenteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

function demand(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function sourceData(string $suffix): array
{
    return [
        'url' => 'https://example.test/m3d1-'.$suffix,
        'nombre' => 'M3d1 synthetic '.$suffix,
        'pais' => 'BO', 'organismo' => 'Synthetic', 'nivel' => 'nacional',
        'tipo' => 'html', 'activo' => false, 'selector_css' => '',
        'autoridades_extractor' => 'structured_css_v1',
        'authority_extraction_config' => [
            'version' => 1, 'container_selector' => 'main', 'row_selector' => 'article',
            'person_selector' => '.person', 'role_selector' => '.role',
            'min_rows' => 1, 'max_rows' => 200,
        ],
        'analizar_imagenes' => false,
    ];
}

function source(array $state, string $key): Fuente
{
    return Fuente::query()->findOrFail($state[$key]);
}

function quiet(array $state): void
{
    foreach (['ready', 'incomplete', 'partial', 'stale'] as $key) {
        $fuente = source($state, $key);
        demand($fuente->snapshots()->count() === 0, "$key created snapshot");
        demand($fuente->cambios()->count() === 0, "$key created Cambio");
        demand($fuente->revisionesRemocionAutoridades()->count() === 0, "$key created removal review");
        demand($fuente->ultimo_check === null, "$key set ultimo_check");
        demand(DB::table('log_fuente_runs')->where('fuente_id', $fuente->id)->count() === 0, "$key created run log");
    }
    demand(DB::table('log_scripts')->count() === 0, 'preview-only created script audit');
    demand(DB::table('gemini_usage_log')->count() === 0, 'preview-only created Gemini usage');
}

try {
    [$script, $phase, $statePath] = $argv + [null, null, null];
    demand(is_string($phase) && is_string($statePath), 'phase and volume-only state path required');
    $service = app(FuenteService::class);
    if ($phase === 'seed') {
        $state = [];
        foreach (['ready', 'incomplete', 'partial', 'stale'] as $key) {
            $fuente = $service->crear(sourceData($key));
            demand(! $fuente->activo && $fuente->authority_extraction_confirmed_revision === null, 'generic source activated at creation');
            $state[$key] = $fuente->id;
            if ($key !== 'stale') {
                $service->solicitarPreviewAutoridades($fuente->id);
            }
        }
        $legacy = $service->crear(array_replace(sourceData('legacy'), [
            'autoridades_extractor' => 'divi_blurb', 'authority_extraction_config' => null,
        ]));
        $state['legacy'] = $legacy->id;
        demand($legacy->authority_extraction_revision === null && $legacy->authority_extraction_confirmed_revision === null, 'legacy revision retrofitted');
        file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
        quiet($state);
    } else {
        $state = json_decode(file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        if ($phase === 'request-stale') {
            $service->solicitarPreviewAutoridades(source($state, 'stale')->id);
        } elseif ($phase === 'confirm-ready') {
            $fuente = source($state, 'ready');
            $row = AuthorityExtractionPreview::where('fuente_id', $fuente->id)->firstOrFail();
            demand($row->status === 'ready' && preg_match('/\A[a-f0-9]{64}\z/D', $row->config_fingerprint) === 1, 'missing Python ready fingerprint');
            demand($row->authorities_json === [['cargo' => 'Rectora', 'persona' => 'Ada Lovelace']], 'ready roster differs');
            demand(! $fuente->activo && $fuente->authority_extraction_confirmed_revision === null, 'Python activated source');
            $confirmed = $service->confirmarPreviewAutoridades($fuente->id, $service->previewResultIdentity($fuente, $row));
            demand(! $confirmed->activo && $confirmed->authority_extraction_confirmed_revision === $confirmed->authority_extraction_revision, 'confirm must leave source inactive');
            demand($service->toggleActivo($fuente->id)->activo, 'explicit toggle failed');
        } elseif ($phase === 'reject-incomplete') {
            $partial = AuthorityExtractionPreview::where('fuente_id', source($state, 'partial')->id)->firstOrFail();
            demand($partial->status === 'incomplete' && $partial->authorities_json === null && $partial->error_code === 'field_scope_invalid', 'partial result not sanitized');
            $partialFuente = source($state, 'partial');
            try {
                $service->confirmarPreviewAutoridades($partialFuente->id, str_repeat('a', 64));
                throw new RuntimeException('partial preview was confirmed');
            } catch (ValidationException) {
                // Expected rejection.
            }
            demand(! $service->toggleActivo($partialFuente->id)->activo, 'partial source activated');
            $fuente = source($state, 'incomplete');
            $row = AuthorityExtractionPreview::where('fuente_id', $fuente->id)->firstOrFail();
            demand($row->status === 'incomplete' && $row->authorities_json === null && $row->error_code === 'duplicate_authority', 'incomplete result not sanitized');
            try {
                $service->confirmarPreviewAutoridades($fuente->id, str_repeat('a', 64));
                throw new RuntimeException('incomplete preview was confirmed');
            } catch (ValidationException) {
                // Expected rejection, not a test failure.
            }
            demand(! $service->toggleActivo($fuente->id)->activo, 'incomplete source activated');
            demand($fuente->fresh()->authority_extraction_confirmed_revision === null, 'incomplete source confirmed');
        } elseif ($phase === 'edit-stale') {
            $fuente = source($state, 'stale');
            $previous = $fuente->authority_extraction_revision;
            $changed = $service->actualizar($fuente->id, ['url' => 'https://example.test/m3d1-stale-new']);
            demand($changed->authority_extraction_revision !== $previous, 'edit did not rotate revision');
            $row = AuthorityExtractionPreview::where('fuente_id', $fuente->id)->firstOrFail();
            demand($row->status === 'stale' && $row->lease_token === null && $row->authorities_json === null, 'edit did not invalidate claim');
            $service->solicitarPreviewAutoridades($fuente->id);
        } elseif ($phase === 'confirm-fresh') {
            $fuente = source($state, 'stale');
            $row = AuthorityExtractionPreview::where('fuente_id', $fuente->id)->firstOrFail();
            demand($row->status === 'ready' && $row->source_url === $fuente->url, 'fresh preview not ready');
            demand($row->authorities_json === [['cargo' => 'Rectora', 'persona' => 'Grace Hopper']], 'old roster accepted');
            $confirmed = $service->confirmarPreviewAutoridades($fuente->id, $service->previewResultIdentity($fuente, $row));
            demand(! $confirmed->activo && $confirmed->authority_extraction_confirmed_revision === $confirmed->authority_extraction_revision, 'fresh confirmation invalid');
        } else {
            throw new RuntimeException('unknown phase');
        }
        $legacy = source($state, 'legacy');
        demand($legacy->authority_extraction_revision === null && $legacy->authorityExtractionPreview()->count() === 0, 'legacy source retrofitted');
        quiet($state);
    }
    echo "PASS $phase\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL '.($phase ?? 'bootstrap').': '.$error->getMessage()."\n");
    exit(1);
}
