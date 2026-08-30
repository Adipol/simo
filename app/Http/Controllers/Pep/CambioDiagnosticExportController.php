<?php

declare(strict_types=1);

namespace App\Http\Controllers\Pep;

use App\Http\Controllers\Controller;
use App\Services\Pep\CambioDiagnosticExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CambioDiagnosticExportController extends Controller
{
    public function __invoke(CambioDiagnosticExportService $exporter): StreamedResponse
    {
        return response()->streamDownload(
            static function () use ($exporter): void {
                foreach ($exporter->ndjsonLines() as $line) {
                    echo $line;
                }
            },
            CambioDiagnosticExportService::FILENAME,
            [
                'Content-Type' => 'application/x-ndjson; charset=UTF-8',
                'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
                'Pragma' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
