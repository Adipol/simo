<?php

declare(strict_types=1);

namespace App\Services\PepDirectory\DTOs;

use Illuminate\Validation\ValidationException;

/** Mapping only: source, URL, evidence and authority always belong to the server. */
final readonly class DirectoryMappingData
{
    private function __construct(public array $mapping) {}

    public static function fromArray(array $input): self
    {
        self::check(array_diff(['directory', 'record', 'fields'], array_keys($input)) === [] && array_diff(array_keys($input), ['directory', 'record', 'fields', 'section', 'columns']) === []);
        foreach (['directory', 'record', 'section'] as $key) {
            if (array_key_exists($key, $input)) {
                self::text($input[$key]);
            }
        }
        self::check(! array_key_exists('columns', $input) || (is_int($input['columns']) && $input['columns'] >= 1 && $input['columns'] <= 32));
        self::check(is_array($input['fields']) && array_diff(['unit', 'role', 'person'], array_keys($input['fields'])) === [] && array_diff(array_keys($input['fields']), ['unit', 'role', 'person', 'condition']) === []);
        foreach ($input['fields'] as $name => $field) {
            self::check(is_array($field));
            if (array_key_exists('constant', $field)) {
                self::check($name === 'unit' && array_keys($field) === ['constant']);
                self::text($field['constant']);
            } elseif (array_key_exists('column', $field)) {
                self::check(array_keys($field) === ['column'] && is_int($field['column']) && $field['column'] >= 0 && isset($input['columns']) && $field['column'] < $input['columns']);
            } else {
                self::check(isset($field['selector']) && array_diff(array_keys($field), ['selector', 'scope']) === []);
                self::text($field['selector']);
                $scope = $field['scope'] ?? 'record';
                self::check(in_array($scope, ['record', 'directory', 'section'], true) && ($name === 'unit' || $scope === 'record') && ($scope !== 'section' || isset($input['section'])));
            }
        }
        return new self($input);
    }

    /** mixed is the not-yet-validated mapping scalar boundary. */
    private static function text(mixed $value): void
    {
        self::check(is_string($value) && trim($value) !== '' && strlen($value) <= 512 && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0"));
    }

    private static function check(bool $condition): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['mapping' => 'Only bounded selectors, columns and field associations are accepted.']);
        }
    }
}
