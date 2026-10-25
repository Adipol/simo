<?php

declare(strict_types=1);

namespace App\Services\PepDirectory\DTOs;

use Illuminate\Validation\ValidationException;

final readonly class ConfigurationDecisionData
{
    private function __construct(public int $expectedVersion, public string $reason) {}

    /** @param array<string, mixed> $input Untrusted request boundary. */
    public static function fromArray(array $input): self
    {
        $errors = [];
        foreach (array_diff(array_keys($input), ['expected_version', 'reason']) as $key) {
            $errors[$key] = ['This field cannot be supplied for a configuration decision.'];
        }
        $version = $input['expected_version'] ?? null;
        if (! is_int($version) || $version < 0 || $version >= PHP_INT_MAX) {
            $errors['expected_version'] = ['Expected version must be a nonnegative integer below PHP_INT_MAX.'];
        }
        $reason = $input['reason'] ?? null;
        if (! is_string($reason) || ! mb_check_encoding($reason, 'UTF-8') || str_contains($reason, "\0")
            || preg_match('/\A\s*\z/u', $reason) === 1 || mb_strlen($reason) > 10000) {
            $errors['reason'] = ['Reason must be nonblank UTF-8 text of at most 10000 characters without NUL bytes.'];
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return new self($version, $reason);
    }
}
