<?php

declare(strict_types=1);

namespace App\Exceptions\PepDirectory;

use RuntimeException;

final class ConfigurationConflictException extends RuntimeException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $actualVersion)
    {
        parent::__construct('Configuration eligibility or binding changed. Reload a current stored preview before deciding.');
    }
}
