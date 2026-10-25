<?php

declare(strict_types=1);

namespace App\Exceptions\PepDirectory;

use RuntimeException;

final class ReviewConflictException extends RuntimeException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $actualVersion)
    {
        parent::__construct('The proposal has a newer review decision. Reload before deciding.');
    }
}
