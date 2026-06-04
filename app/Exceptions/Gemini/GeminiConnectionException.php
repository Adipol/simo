<?php

declare(strict_types=1);

namespace App\Exceptions\Gemini;

/**
 * Tier-2 transient exception: wraps Illuminate\Http\Client\ConnectionException.
 *
 * Propagates without being caught by per-record Tier-1 handlers, allowing
 * the job to retry via its backoff schedule ([5, 25, 125] seconds).
 * Never triggers marcarFallido — the record stays pending for retry.
 */
class GeminiConnectionException extends GeminiException
{
    public function __construct(string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
