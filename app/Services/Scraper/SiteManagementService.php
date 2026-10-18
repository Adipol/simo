<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Enums\SiteValidationStatus;
use App\Jobs\ValidateScraperSite;
use App\Models\SitioWeb;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class SiteManagementService
{
    /** @param array<string, mixed> $data */
    public function save(?SitioWeb $site, array $data): SitioWeb
    {
        $site ??= new SitioWeb;
        $validationChanged = ! $site->exists || collect(['url', 'selector_links', 'selector_article'])
            ->contains(fn (string $field): bool => $site->getAttribute($field) !== ($data[$field] ?: null));
        $activationRequested = (bool) Arr::pull($data, 'activo', true);
        $orphanedPendingValidation = $site->exists
            && $activationRequested
            && $site->validation_status === SiteValidationStatus::Pending
            && ($site->validation_token === null || $site->validation_requested_at === null);
        $shouldStartValidation = $validationChanged || $orphanedPendingValidation;
        $site->fill($data);
        $site->activation_requested = $activationRequested;

        if ($shouldStartValidation) {
            $this->markPending($site);
        } elseif ($site->validation_status === SiteValidationStatus::Valid) {
            $site->activo = $activationRequested;
        } else {
            $site->activo = false;
        }

        $site->save();
        if ($shouldStartValidation) {
            ValidateScraperSite::dispatch($site->id, $site->validation_token)->afterCommit();
        }

        return $site;
    }

    public function toggleActive(SitioWeb $site): void
    {
        if ($site->activo) {
            $site->update(['activo' => false, 'activation_requested' => false]);

            return;
        }
        $site->activation_requested = true;
        if ($site->validation_status === SiteValidationStatus::Valid) {
            $site->activo = true;
            $site->save();

            return;
        }

        $this->retry($site);
    }

    public function retry(SitioWeb $site): void
    {
        $this->markPending($site);
        $site->save();
        ValidateScraperSite::dispatch($site->id, $site->validation_token)->afterCommit();
    }

    private function markPending(SitioWeb $site): void
    {
        $site->validation_status = SiteValidationStatus::Pending;
        $site->validation_token = (string) Str::uuid();
        $site->validation_requested_at = now();
        $site->validation_started_at = null;
        $site->validated_at = null;
        $site->validation_diagnostic = 'Validación pendiente de ejecución.';
        $site->validation_resolved_url = null;
        $site->activo = false;
    }
}
