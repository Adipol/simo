<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SiteValidationStatus;
use App\Models\SitioWeb;
use App\Services\Scraper\SiteUrlValidator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Query\Expression;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ValidateScraperSite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public int $timeout = 45;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $siteId, public readonly string $validationToken)
    {
        $this->onQueue('site-validation');
    }

    public function handle(SiteUrlValidator $validator): void
    {
        $claimed = SitioWeb::query()
            ->whereKey($this->siteId)
            ->where('validation_token', $this->validationToken)
            ->where('validation_status', SiteValidationStatus::Pending)
            ->update([
                'validation_status' => SiteValidationStatus::Validating,
                'validation_started_at' => now(),
                'validation_diagnostic' => 'Validación en curso desde el servidor.',
            ]);
        if ($claimed !== 1) {
            return;
        }

        $site = SitioWeb::query()->whereKey($this->siteId)->where('validation_token', $this->validationToken)->first();
        if ($site === null) {
            return;
        }

        try {
            $result = $validator->validate($site->url, $site->selector_links, $site->selector_article);
            SitioWeb::query()
                ->whereKey($site->id)
                ->where('validation_token', $this->validationToken)
                ->where('validation_status', SiteValidationStatus::Validating)
                ->update([
                    'validation_status' => $result->valid ? SiteValidationStatus::Valid : SiteValidationStatus::Failed,
                    'activo' => $result->valid ? new Expression('activation_requested') : false,
                    'validated_at' => now(),
                    'validation_diagnostic' => $result->diagnostic,
                    'validation_resolved_url' => $result->resolvedUrl,
                ]);
        } catch (Throwable $exception) {
            SitioWeb::query()
                ->whereKey($site->id)
                ->where('validation_token', $this->validationToken)
                ->where('validation_status', SiteValidationStatus::Validating)
                ->update([
                    'validation_status' => SiteValidationStatus::Pending,
                    'validation_started_at' => null,
                    'validation_diagnostic' => 'Validación pendiente de reintento automático.',
                ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        SitioWeb::query()
            ->whereKey($this->siteId)
            ->where('validation_token', $this->validationToken)
            ->whereIn('validation_status', [SiteValidationStatus::Pending, SiteValidationStatus::Validating])
            ->update([
                'validation_status' => SiteValidationStatus::Failed,
                'activo' => false,
                'validated_at' => now(),
                'validation_diagnostic' => 'La validación no pudo completarse: '.($exception?->getMessage() ?? 'error desconocido'),
            ]);
    }
}
