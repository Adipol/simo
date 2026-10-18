<?php

declare(strict_types=1);

namespace App\DTOs\Pep;

use Illuminate\Validation\ValidationException;

final readonly class AuthorityExtractionConfig
{
    private const VERSION = 1;

    private const MAX_SELECTOR_LENGTH = 500;

    private const MAX_ROWS = 200;

    /**
     * @param  array<string, scalar|null>  $config
     */
    private function __construct(
        public string $containerSelector,
        public string $rowSelector,
        public string $personSelector,
        public string $roleSelector,
        public int $minRows,
        public int $maxRows,
    ) {}

    /**
     * @param  array<string, scalar|null>  $config
     */
    public static function fromArray(array $config): self
    {
        $allowed = [
            'version',
            'container_selector',
            'row_selector',
            'person_selector',
            'role_selector',
            'min_rows',
            'max_rows',
        ];

        if (array_diff(array_keys($config), $allowed) !== []
            || isset($config['version']) === false
            || is_int($config['version']) === false
            || $config['version'] !== self::VERSION) {
            throw self::invalid();
        }

        $selectors = [];
        foreach (['container_selector', 'row_selector', 'person_selector', 'role_selector'] as $field) {
            if (array_key_exists($field, $config) === false || is_string($config[$field]) === false) {
                throw self::invalid();
            }

            $value = preg_replace('/^\s+|\s+$/u', '', $config[$field]);
            if ($value === null || $value === '' || mb_strlen($value) > self::MAX_SELECTOR_LENGTH) {
                throw self::invalid();
            }

            $selectors[$field] = $value;
        }

        $minRows = $config['min_rows'] ?? 1;
        $maxRows = $config['max_rows'] ?? self::MAX_ROWS;
        if (is_int($minRows) === false || is_int($maxRows) === false
            || $minRows < 1 || $minRows > $maxRows || $maxRows > self::MAX_ROWS) {
            throw self::invalid();
        }

        return new self(
            $selectors['container_selector'],
            $selectors['row_selector'],
            $selectors['person_selector'],
            $selectors['role_selector'],
            $minRows,
            $maxRows,
        );
    }

    /**
     * @return array{version: int, container_selector: string, row_selector: string, person_selector: string, role_selector: string, min_rows: int, max_rows: int}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'container_selector' => $this->containerSelector,
            'row_selector' => $this->rowSelector,
            'person_selector' => $this->personSelector,
            'role_selector' => $this->roleSelector,
            'min_rows' => $this->minRows,
            'max_rows' => $this->maxRows,
        ];
    }

    private static function invalid(): ValidationException
    {
        return ValidationException::withMessages([
            'authority_extraction_config' => ['La configuración de extracción de autoridades no es válida.'],
        ]);
    }
}
