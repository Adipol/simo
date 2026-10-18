<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Enums\SiteValidationStatus;
use App\Jobs\ValidateScraperSite;
use App\Models\SitioWeb;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Support\Str;
use Throwable;

final class RecoverAbandonedSiteValidations
{
    public function __construct(private readonly QueueingDispatcher $dispatcher) {}

    public function recover(): int
    {
        $pendingCutoff = now()->subMinutes((int) config('scraper.validation_pending_redispatch_after_minutes'));
        $validatingCutoff = now()->subMinutes((int) config('scraper.validation_abandoned_after_minutes'));
        $recovered = 0;

        SitioWeb::query()
            ->where('validation_status', SiteValidationStatus::Pending)
            ->where('validation_requested_at', '<=', $pendingCutoff)
            ->whereNotNull('validation_token')
            ->select(['id', 'validation_token', 'validation_requested_at'])
            ->orderBy('id')
            ->each(function (SitioWeb $site) use ($pendingCutoff, &$recovered): void {
                $claimTimestamp = now();
                $claimed = SitioWeb::query()
                    ->whereKey($site->id)
                    ->where('validation_token', $site->validation_token)
                    ->where('validation_status', SiteValidationStatus::Pending)
                    ->where('validation_requested_at', '<=', $pendingCutoff)
                    ->update(['validation_requested_at' => $claimTimestamp]);
                if ($claimed !== 1) {
                    return;
                }

                try {
                    $this->dispatcher->dispatch(new ValidateScraperSite($site->id, (string) $site->validation_token));
                } catch (Throwable $exception) {
                    SitioWeb::query()
                        ->whereKey($site->id)
                        ->where('validation_token', $site->validation_token)
                        ->where('validation_status', SiteValidationStatus::Pending)
                        ->where('validation_requested_at', $claimTimestamp)
                        ->update(['validation_requested_at' => $site->validation_requested_at]);

                    throw $exception;
                }

                $recovered++;
            });

        SitioWeb::query()
            ->where('validation_status', SiteValidationStatus::Validating)
            ->where('validation_started_at', '<=', $validatingCutoff)
            ->whereNotNull('validation_token')
            ->select(['id', 'validation_token'])
            ->orderBy('id')
            ->each(function (SitioWeb $site) use ($pendingCutoff, $validatingCutoff, &$recovered): void {
                $newToken = (string) Str::uuid();
                $claimed = SitioWeb::query()
                    ->whereKey($site->id)
                    ->where('validation_token', $site->validation_token)
                    ->where('validation_status', SiteValidationStatus::Validating)
                    ->where('validation_started_at', '<=', $validatingCutoff)
                    ->update([
                        'validation_status' => SiteValidationStatus::Pending,
                        'validation_token' => $newToken,
                        'validation_requested_at' => $pendingCutoff,
                        'validation_started_at' => null,
                        'validation_diagnostic' => 'Validación recuperada tras exceder el tiempo máximo de ejecución.',
                    ]);

                if ($claimed !== 1) {
                    return;
                }

                $this->dispatcher->dispatch(new ValidateScraperSite($site->id, $newToken));
                $recovered++;
            });

        return $recovered;
    }
}
