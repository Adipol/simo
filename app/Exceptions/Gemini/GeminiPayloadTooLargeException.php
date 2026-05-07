<?php

declare(strict_types=1);

namespace App\Exceptions\Gemini;

class GeminiPayloadTooLargeException extends GeminiException
{
    public function __construct(int $payloadSize, int $maxBytes)
    {
        parent::__construct(
            "Gemini multimodal payload size {$payloadSize} bytes exceeds limit of {$maxBytes} bytes"
        );
    }
}
