<?php

declare(strict_types=1);

namespace App\Services\PepDirectory;

use App\Services\PepDirectory\DTOs\CompiledPreviewData;

/** Internal server-owned inputs only; not an HTTP/artifact ingestion contract. */
interface PreviewCompiler
{
    public function compile(array $declaration, string $rawBody, array $captureMetadata): CompiledPreviewData;
}
