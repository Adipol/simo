<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs;

use App\DTOs\Pep\AuthorityExtractionConfig;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AuthorityExtractionConfigTest extends TestCase
{
    public function test_it_normalizes_defaults_and_selector_whitespace_in_a_stable_order(): void
    {
        $config = AuthorityExtractionConfig::fromArray([
            'role_selector' => ' .role ',
            'version' => 1,
            'person_selector' => ' .person ',
            'container_selector' => ' main ',
            'row_selector' => ' article ',
        ]);

        $this->assertSame([
            'version' => 1,
            'container_selector' => 'main',
            'row_selector' => 'article',
            'person_selector' => '.person',
            'role_selector' => '.role',
            'min_rows' => 1,
            'max_rows' => 200,
        ], $config->toArray());
    }

    /**
     * @dataProvider invalidConfigProvider
     *
     * @param  array<string, mixed>  $config
     */
    public function test_it_rejects_invalid_shapes(array $config): void
    {
        $this->expectException(ValidationException::class);

        AuthorityExtractionConfig::fromArray($config);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidConfigProvider(): array
    {
        $valid = self::validConfig();

        return [
            'boolean version' => [array_replace($valid, ['version' => true])],
            'wrong version' => [array_replace($valid, ['version' => 2])],
            'boolean minimum' => [array_replace($valid, ['min_rows' => false])],
            'boolean maximum' => [array_replace($valid, ['max_rows' => true])],
            'minimum below one' => [array_replace($valid, ['min_rows' => 0])],
            'minimum above maximum' => [array_replace($valid, ['min_rows' => 3, 'max_rows' => 2])],
            'maximum above cap' => [array_replace($valid, ['max_rows' => 201])],
            'unknown field' => [array_replace($valid, ['unexpected' => 'value'])],
            'blank selector' => [array_replace($valid, ['row_selector' => " \t "])],
            'too long selector' => [array_replace($valid, ['role_selector' => str_repeat('á', 501)])],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function validConfig(): array
    {
        return [
            'version' => 1,
            'container_selector' => 'main',
            'row_selector' => 'article',
            'person_selector' => '.person',
            'role_selector' => '.role',
        ];
    }
}
