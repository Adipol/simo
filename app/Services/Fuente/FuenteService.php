<?php

declare(strict_types=1);

namespace App\Services\Fuente;

use App\DTOs\Pep\AuthorityExtractionConfig;
use App\Enums\AuthorityRemovalReviewStatus;
use App\Models\AuthorityExtractionPreview;
use App\Models\AuthorityRemovalReview;
use App\Models\Fuente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FuenteService
{
    private const GENERIC_EXTRACTOR = 'structured_css_v1';

    private const HTML_TYPE = 'html';

    /**
     * Lista paginada de fuentes con filtros opcionales.
     *
     * @param  array{busqueda?:string,nivel?:string,activo?:bool|string,pais?:string}  $filtros
     */
    public function paginar(array $filtros, int $perPage = 20): LengthAwarePaginator
    {
        $q = Fuente::withCount(['cambios as cambios_sin_revisar' => fn ($q) => $q->where('revisado', false)]);

        if (! empty($filtros['busqueda'])) {
            $b = '%'.$filtros['busqueda'].'%';
            $q->where(fn ($s) => $s->where('nombre', 'like', $b)
                ->orWhere('url', 'like', $b)
                ->orWhere('organismo', 'like', $b));
        }

        if (! empty($filtros['nivel'])) {
            $q->where('nivel', $filtros['nivel']);
        }

        if (array_key_exists('activo', $filtros) && $filtros['activo'] !== '' && $filtros['activo'] !== null) {
            $q->where('activo', (bool) $filtros['activo']);
        }

        if (! empty($filtros['pais'])) {
            $q->where('pais', $filtros['pais']);
        }

        return $q->with(['paisRelacion', 'authorityExtractionPreview'])->orderBy('nombre')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function crear(array $data): Fuente
    {
        $this->rejectTrustedMetadata($data);
        $attributes = $this->normalizedAttributes($data, null);

        return Fuente::create($attributes)->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function actualizar(int $id, array $data): Fuente
    {
        $this->rejectTrustedMetadata($data);

        return DB::transaction(function () use ($id, $data): Fuente {
            $fuente = Fuente::query()->lockForUpdate()->findOrFail($id);
            $attributes = $this->normalizedAttributes($data, $fuente);

            if ($this->identityChanged($fuente, $attributes)) {
                $attributes['authority_extraction_revision'] = (string) Str::uuid();
                $attributes['authority_extraction_confirmed_revision'] = null;
                $this->supersedePendingReviews($fuente);
                $this->invalidatePreview($fuente);
            }

            $effectiveAttributes = array_replace($fuente->getAttributes(), $attributes);
            if ($this->isGeneric($effectiveAttributes)) {
                $attributes['activo'] = $this->canActivate($effectiveAttributes) && (bool) $effectiveAttributes['activo'];
            }

            $fuente->update($attributes);

            return $fuente->refresh();
        });
    }

    public function toggleActivo(int $id): Fuente
    {
        return DB::transaction(function () use ($id): Fuente {
            $fuente = Fuente::query()->lockForUpdate()->findOrFail($id);
            $activo = $fuente->activo === false;

            if ($activo && $this->isGeneric($fuente->getAttributes())) {
                $activo = $this->canActivate($fuente->getAttributes());
            }

            $fuente->update(['activo' => $activo]);

            return $fuente->refresh();
        });
    }

    public function solicitarPreviewAutoridades(int $id): AuthorityExtractionPreview
    {
        return DB::transaction(function () use ($id): AuthorityExtractionPreview {
            $fuente = Fuente::query()->lockForUpdate()->findOrFail($id);
            $this->validatePreviewSource($fuente);
            $preview = AuthorityExtractionPreview::query()
                ->where('fuente_id', $fuente->id)
                ->lockForUpdate()
                ->first();
            $attributes = [
                'source_revision' => $fuente->authority_extraction_revision,
                'source_url' => $fuente->url,
                'status' => 'pending',
                'attempts' => 0,
                'lease_token' => null,
                'lease_expires_at' => null,
                'config_fingerprint' => null,
                'authorities_json' => null,
                'error_code' => null,
                'requested_at' => now(),
                'completed_at' => null,
            ];

            if ($preview === null) {
                return AuthorityExtractionPreview::create(['fuente_id' => $fuente->id] + $attributes);
            }

            $preview->update($attributes);

            return $preview->refresh();
        });
    }

    public function confirmarPreviewAutoridades(int $id): Fuente
    {
        return DB::transaction(function () use ($id): Fuente {
            $fuente = Fuente::query()->lockForUpdate()->findOrFail($id);
            $this->validatePreviewSource($fuente);
            $preview = AuthorityExtractionPreview::query()
                ->where('fuente_id', $fuente->id)
                ->lockForUpdate()
                ->first();

            if (! $this->previewConfirmable($fuente, $preview)) {
                throw $this->invalidSourceConfiguration();
            }

            $fuente->update(['authority_extraction_confirmed_revision' => $fuente->authority_extraction_revision]);

            return $fuente->refresh();
        });
    }

    public function previewConfirmable(Fuente $fuente, ?AuthorityExtractionPreview $preview): bool
    {
        try {
            $this->validatePreviewSource($fuente);
        } catch (ValidationException) {
            return false;
        }

        return $preview !== null && $preview->status === 'ready'
            && $preview->source_revision === $fuente->authority_extraction_revision
            && $preview->source_url === $fuente->url
            && $preview->completed_at !== null && $preview->error_code === null
            && $preview->lease_token === null && $preview->lease_expires_at === null
            && is_string($preview->config_fingerprint)
            && preg_match('/\A[a-f0-9]{64}\z/D', $preview->config_fingerprint) === 1
            && $this->validPreviewAuthorities($preview->authorities_json);
    }

    private function validatePreviewSource(Fuente $fuente): void
    {
        if ($fuente->tipo !== self::HTML_TYPE || $fuente->autoridades_extractor !== self::GENERIC_EXTRACTOR
            || ! is_string($fuente->url) || $fuente->url === ''
            || ! is_string($fuente->authority_extraction_revision)
            || ! Str::isUuid($fuente->authority_extraction_revision)
            || ! is_array($fuente->authority_extraction_config)) {
            throw $this->invalidSourceConfiguration();
        }

        AuthorityExtractionConfig::fromArray($fuente->authority_extraction_config);
    }

    private function validPreviewAuthorities(mixed $authorities): bool
    {
        if (! is_array($authorities) || ! array_is_list($authorities)
            || count($authorities) < 1 || count($authorities) > 200) {
            return false;
        }

        foreach ($authorities as $authority) {
            if (! is_array($authority) || count($authority) !== 2
                || ! array_key_exists('cargo', $authority) || ! array_key_exists('persona', $authority)) {
                return false;
            }

            foreach (['cargo', 'persona'] as $field) {
                $value = $authority[$field];
                if (! is_string($value) || $value === '' || $value !== trim($value)
                    || mb_strlen($value) > 300) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizedAttributes(array $data, ?Fuente $fuente): array
    {
        $attributes = $data;
        $extractor = array_key_exists('autoridades_extractor', $data)
            ? $data['autoridades_extractor']
            : $fuente?->autoridades_extractor;
        $type = array_key_exists('tipo', $data) ? $data['tipo'] : ($fuente?->tipo ?? self::HTML_TYPE);
        $config = array_key_exists('authority_extraction_config', $data)
            ? $data['authority_extraction_config']
            : $fuente?->authority_extraction_config;
        if ($extractor === self::GENERIC_EXTRACTOR && $type !== self::HTML_TYPE) {
            if (($data['autoridades_extractor'] ?? null) === self::GENERIC_EXTRACTOR) {
                throw $this->invalidSourceConfiguration();
            }

            $extractor = null;
            $config = null;
        }

        if ($extractor === self::GENERIC_EXTRACTOR) {
            if (is_array($config) === false) {
                throw $this->invalidSourceConfiguration();
            }

            $attributes['authority_extraction_config'] = AuthorityExtractionConfig::fromArray($config)->toArray();
        } else {
            if ($config !== null) {
                throw $this->invalidSourceConfiguration();
            }

            $attributes['authority_extraction_config'] = null;
        }

        $attributes['autoridades_extractor'] = $extractor;

        if ($fuente === null && $extractor === self::GENERIC_EXTRACTOR) {
            $attributes['authority_extraction_revision'] = (string) Str::uuid();
            $attributes['authority_extraction_confirmed_revision'] = null;
            $attributes['activo'] = false;
        }

        return $attributes;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function identityChanged(Fuente $fuente, array $attributes): bool
    {
        return $this->effectiveAttribute($fuente, $attributes, 'url') !== $fuente->url
            || $this->effectiveAttribute($fuente, $attributes, 'tipo') !== $fuente->tipo
            || $this->effectiveAttribute($fuente, $attributes, 'autoridades_extractor') !== $fuente->autoridades_extractor
            || $this->effectiveAttribute($fuente, $attributes, 'authority_extraction_config') !== $fuente->authority_extraction_config;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function effectiveAttribute(Fuente $fuente, array $attributes, string $key): string|array|null
    {
        return array_key_exists($key, $attributes) ? $attributes[$key] : $fuente->getAttribute($key);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function isGeneric(array $attributes): bool
    {
        return ($attributes['autoridades_extractor'] ?? null) === self::GENERIC_EXTRACTOR;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function canActivate(array $attributes): bool
    {
        $revision = $attributes['authority_extraction_revision'] ?? null;
        $confirmedRevision = $attributes['authority_extraction_confirmed_revision'] ?? null;

        return is_string($revision) && $revision !== ''
            && is_string($confirmedRevision) && hash_equals($revision, $confirmedRevision);
    }

    private function invalidatePreview(Fuente $fuente): void
    {
        $preview = AuthorityExtractionPreview::query()
            ->where('fuente_id', $fuente->id)
            ->lockForUpdate()
            ->first();

        $preview?->update([
            'status' => 'stale',
            'authorities_json' => null,
            'config_fingerprint' => null,
            'error_code' => null,
            'lease_token' => null,
            'lease_expires_at' => null,
            'completed_at' => null,
        ]);
    }

    private function supersedePendingReviews(Fuente $fuente): void
    {
        AuthorityRemovalReview::query()
            ->where('fuente_id', $fuente->id)
            ->where('estado', AuthorityRemovalReviewStatus::Pending->value)
            ->eachById(function (AuthorityRemovalReview $review): void {
                $review->update([
                    'estado' => AuthorityRemovalReviewStatus::Superseded,
                    'lifecycle_key' => $review->id,
                ]);
            });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function rejectTrustedMetadata(array $data): void
    {
        if (array_key_exists('authority_extraction_revision', $data)
            || array_key_exists('authority_extraction_confirmed_revision', $data)) {
            throw $this->invalidSourceConfiguration();
        }
    }

    private function invalidSourceConfiguration(): ValidationException
    {
        return ValidationException::withMessages([
            'authority_extraction_config' => ['La configuración de extracción de autoridades no es válida.'],
        ]);
    }
}
