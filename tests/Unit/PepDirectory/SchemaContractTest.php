<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use PHPUnit\Framework\TestCase;
use Tests\Support\PepDirectorySchemaContract;

final class SchemaContractTest extends TestCase
{
    private function api(): void
    {
        $path = dirname(__DIR__, 2).'/Support/PepDirectorySchemaContract.php';
        self::assertFileExists($path, 'public validate(snapshot) API must exist');
        require_once $path;
        self::assertTrue(method_exists(PepDirectorySchemaContract::class, 'validate'));
    }

    private function contract(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/PepDirectory/schema-contracts/contract.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** Isolated copied validator; the checkout manifest is never written. */
    private function probeContract(array|string|int|float|bool|null $manifest, array|string|int|float|bool|null $snapshot): array
    {
        $root = sys_get_temp_dir().'/pep-schema-contract-check-'.bin2hex(random_bytes(16));
        $directories = [];
        $files = [];
        $process = null;
        $pipes = [];
        try {
            foreach (['', '/Support', '/Fixtures', '/Fixtures/PepDirectory', '/Fixtures/PepDirectory/schema-contracts'] as $suffix) {
                $directory = $root.$suffix;
                self::assertTrue(mkdir($directory, 0700), 'create isolated probe directory');
                $directories[] = $directory;
            }
            $validator = $root.'/Support/PepDirectorySchemaContract.php';
            $files[] = $validator;
            self::assertTrue(copy(dirname(__DIR__, 2).'/Support/PepDirectorySchemaContract.php', $validator));
            $contract = $root.'/Fixtures/PepDirectory/schema-contracts/contract.json';
            $files[] = $contract;
            $json = json_encode($manifest, JSON_THROW_ON_ERROR);
            self::assertSame(strlen($json), file_put_contents($contract, $json));
            $code = 'require '.var_export($validator, true).';'
                .'$snapshot = json_decode('.var_export(json_encode($snapshot, JSON_THROW_ON_ERROR), true).', true, 512, JSON_THROW_ON_ERROR);'
                .'echo Tests\\Support\\PepDirectorySchemaContract::validate($snapshot);';
            $process = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            $exit = proc_close($process);
            $process = null;

            return [$exit, $stdout, $stderr];
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                proc_close($process);
            }
            $cleaned = true;
            foreach (array_reverse($files) as $file) {
                if (file_exists($file)) {
                    $cleaned = unlink($file) && $cleaned;
                }
            }
            foreach (array_reverse($directories) as $directory) {
                $cleaned = rmdir($directory) && $cleaned;
            }
            self::assertTrue($cleaned, 'remove only this invocation\'s probe files and directories');
        }
    }

    public function test_malformed_fixed_contract_is_unavailable(): void
    {
        $original = $this->contract();
        $cases = ['null_sections' => [['v1' => null, 'v2_design' => null], null],
            'null_root' => [null, null], 'empty_root' => [[], null]];
        foreach (['v1', 'v2_design'] as $section) {
            foreach (['null' => null, 'scalar' => 'broken', 'empty' => []] as $label => $value) {
                $broken = $original;
                $broken[$section] = $value;
                $cases[$section.'_'.$label] = [$broken, $value];
            }
            $broken = $original;
            unset($broken[$section]);
            $cases[$section.'_missing'] = [$broken, null];
            foreach (['tables', 'triggers', 'functions', 'version', 'owner', 'schema'] as $part) {
                $broken = $original;
                unset($broken[$section][$part]);
                $cases[$section.'_missing_'.$part] = [$broken, $broken[$section]];
            }
            $broken = $original;
            unset($broken[$section]['tables']['fuentes']['columns']['label']);
            $cases[$section.'_truncated_columns'] = [$broken, $broken[$section]];
        }
        foreach ($cases as $label => [$manifest, $snapshot]) {
            self::assertSame([0, 'contract_unavailable', ''], $this->probeContract($manifest, $snapshot), $label);
        }
        $this->api();
        self::assertSame('v1', PepDirectorySchemaContract::validate($original['v1']));
        self::assertSame('unattested_v2', PepDirectorySchemaContract::validate($original['v2_design']));
    }

    public function test_public_api(): void
    {
        $this->api();
    }

    public function test_shared_inventory_and_fail_closed(): void
    {
        $this->api();
        $contract = $this->contract();
        $v1 = $contract['v1'];
        $v2 = $contract['v2_design'];
        self::assertCount(6, $v1['tables']);
        self::assertCount(10, $v2['tables']);
        self::assertSame(['id', 'label'], array_keys($v1['tables']['fuentes']['columns']));
        $before = $v1;
        self::assertSame('v1', PepDirectorySchemaContract::validate($v1));
        self::assertSame($before, $v1);
        self::assertSame('unattested_v2', PepDirectorySchemaContract::validate($v2));
        $cases = [null, [], 'v1', ['version' => 'v1'], ['manifest_path' => 'contract.json']];
        foreach (['version' => 'v2', 'owner' => 'other', 'triggers' => ['unexpected' => []], 'functions' => ['body' => 'anything']] as $key => $value) {
            $changed = $v1;
            $changed[$key] = $value;
            $cases[] = $changed;
        }
        foreach (['tables', 'triggers', 'functions', 'owner', 'schema'] as $section) {
            $changed = $v1;
            unset($changed[$section]);
            $cases[] = $changed;
        }
        foreach (['columns', 'constraints', 'indexes'] as $section) {
            foreach (['missing', 'extra', 'altered'] as $operation) {
                $changed = $v1;
                $entries = &$changed['tables']['pep_directory_sources'][$section];
                $key = array_key_first($entries);
                if ($operation === 'missing') {
                    unset($entries[$key]);
                } elseif ($operation === 'extra') {
                    $entries['unknown'] = $entries[$key];
                } else {
                    $entries[$key] = null;
                }
                unset($entries);
                $cases[] = $changed;
            }
        }
        foreach ([0 => 'integer', 1 => true, 2 => '1'] as $position => $replacement) {
            $changed = $v1;
            $changed['tables']['pep_directory_sources']['columns']['version'][$position] = $replacement;
            $cases[] = $changed;
        }
        foreach (array_keys($v1['tables']) as $table) {
            $changed = $v1;
            unset($changed['tables'][$table]);
            $cases[] = $changed;
        }
        $changed = $v1;
        $changed['tables']['fuentes'] = $v2['tables']['fuentes'];
        $cases[] = $changed;
        $changed = $v1;
        $changed['tables']['unknown'] = $v1['tables']['users'];
        $cases[] = $changed;
        foreach (['functions', 'triggers'] as $section) {
            $changed = $v2;
            $changed[$section] = [];
            $cases[] = $changed;
        }
        foreach (['triggers' => 'pep_fuente_invalidate', 'functions' => 'pep_invalidate_source'] as $section => $name) {
            $changed = $v2;
            $changed[$section][$name][$section === 'functions' ? 'owner' : 'enabled'] = 'unexpected';
            $cases[] = $changed;
        }
        foreach ($cases as $snapshot) {
            $original = $snapshot;
            self::assertSame('schema_mismatch', PepDirectorySchemaContract::validate($snapshot));
            self::assertSame($original, $snapshot);
        }
        // PHP normally ignores extra positional arguments: the API must explicitly refuse.
        self::assertSame('schema_mismatch', PepDirectorySchemaContract::validate($v1, 'v2'));
        self::assertSame('schema_mismatch', PepDirectorySchemaContract::validate($v1, 'contract.json'));
        self::assertSame('v1', PepDirectorySchemaContract::validate(array_reverse($v1, true)));
    }

    public function test_prospective_design_is_not_attestation(): void
    {
        $this->api();
        $design = $this->contract()['v2_design'];
        foreach ($this->contract()['v1']['tables'] as $table => $metadata) {
            foreach ($metadata as $section => $entries) {
                foreach ($entries as $key => $value) {
                    self::assertSame($value, $design['tables'][$table][$section][$key]);
                }
            }
        }
        self::assertNull($design['functions']['pep_invalidate_source']['body_sha256']);
        self::assertSame(['boolean', false, 'false'], $design['tables']['pep_directory_sources']['columns']['configurable']);
        self::assertSame('unattested_v2', PepDirectorySchemaContract::validate($design));
        $design['functions']['pep_invalidate_source']['body_sha256'] = str_repeat('0', 64);
        self::assertSame('schema_mismatch', PepDirectorySchemaContract::validate($design));
        $v1 = $this->contract()['v1'];
        $v1['tables']['fuentes']['columns']['id'][1] = 0;
        self::assertSame('schema_mismatch', PepDirectorySchemaContract::validate($v1));
    }
}
