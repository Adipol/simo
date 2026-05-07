<?php

declare(strict_types=1);

namespace App\Exceptions\Gemini;

class GeminiImageReadException extends GeminiException
{
    public function __construct(string $path)
    {
        parent::__construct("Cannot read image file for multimodal request: {$path}");
    }
}
