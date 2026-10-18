<?php

declare(strict_types=1);

namespace App\Contracts;

interface HostResolver
{
    /** @return list<string> */
    public function resolve(string $host): array;
}
