<?php

declare(strict_types=1);

namespace App\Exceptions\PepDirectory;

final class PreviewCompilationException extends \RuntimeException
{
    public function __construct(public readonly ?int $compilerExitCode = null)
    {
        parent::__construct('Preview compilation failed.');
    }
}
