<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use App\Services\PepDirectory\DTOs\DirectoryMappingData;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\{ArrayLoader, Translator};
use Illuminate\Validation\{Factory, ValidationException};
use PHPUnit\Framework\TestCase;

final class DirectoryMappingDataTest extends TestCase
{
    public function test_optional_role_condition_is_exact_and_conflicts_are_rejected(): void
    {
        $old = Facade::getFacadeApplication();
        $container = new Container();
        $container->instance('validator', new Factory(new Translator(new ArrayLoader(), 'en'), $container));
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('validator');
        try {
            $mapping = ['directory' => 'table', 'record' => 'tr', 'columns' => 3,
                'fields' => ['unit' => ['constant' => 'North'], 'role' => ['column' => 0], 'person' => ['column' => 1]]];
            self::assertSame($mapping, DirectoryMappingData::fromArray($mapping)->mapping);
            $opted = $mapping + ['role_condition' => 'ai_suffix'];
            self::assertSame($opted, DirectoryMappingData::fromArray($opted)->mapping);
            $bad = [];
            foreach ([null, true, 1, [], '', 'AI_SUFFIX', 'other'] as $value) {
                $bad[] = $mapping + ['role_condition' => $value];
            }
            $conflict = $opted;
            $conflict['fields']['condition'] = ['column' => 2];
            $bad[] = $conflict;
            $bad[] = $opted + ['unknown' => true];
            foreach ($bad as $input) {
                try {
                    DirectoryMappingData::fromArray($input);
                    self::fail('Invalid option or explicit condition conflict must be rejected.');
                } catch (ValidationException $error) {
                    self::assertArrayHasKey('mapping', $error->errors());
                }
            }
        } finally {
            Facade::clearResolvedInstance('validator');
            Facade::setFacadeApplication($old);
        }
    }
}
