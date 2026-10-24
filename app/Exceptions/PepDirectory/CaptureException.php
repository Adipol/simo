<?php

declare(strict_types=1);

namespace App\Exceptions\PepDirectory;

final class CaptureException extends \RuntimeException
{
    public function __construct(public readonly ?int $captureExitCode = null)
    {
        parent::__construct('Directory capture failed.');
    }
}
