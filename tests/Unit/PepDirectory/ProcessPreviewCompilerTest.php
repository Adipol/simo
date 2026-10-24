<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use App\Exceptions\PepDirectory\PreviewCompilationException;
use App\Services\PepDirectory\DTOs\CompiledPreviewData;
use App\Services\PepDirectory\PreviewCompiler;
use App\Services\PepDirectory\ProcessPreviewCompiler;
use PHPUnit\Framework\TestCase;

final class ProcessPreviewCompilerTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/PepDirectory/preview-compiler/';

    private function request(): array
    {
        return json_decode(file_get_contents(self::FIXTURES.'request.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    private function artifact(): string
    {
        return file_get_contents(self::FIXTURES.'artifact.json');
    }

    public function test_public_contract_exists(): void
    {
        self::assertTrue(interface_exists(PreviewCompiler::class), 'Typed internal compiler contract is required.');
        self::assertTrue(class_exists(ProcessPreviewCompiler::class), 'Controlled adapter is required.');
        self::assertTrue(class_exists(CompiledPreviewData::class), 'Strict decoded artifact is required.');
    }

    public function test_actual_python_artifact_decodes_without_mutating_request(): void
    {
        if (! class_exists(CompiledPreviewData::class)) {
            self::fail('Actual compiler output must have a strict decoder API.');
        }
        $request = $this->request();
        $before = $request;
        $data = CompiledPreviewData::fromOutput($this->artifact(), $request['declaration'], base64_decode($request['body_base64']), $request['metadata']);
        self::assertSame('incomplete', $data->validity);
        self::assertSame([], $data->wire['positions']);
        self::assertSame($before, $request);
        self::assertSame(hash('sha256', $data->resultBytes), $data->resultSha256);
    }

    private function decode(string $output): CompiledPreviewData
    {
        $r = $this->request();
        return CompiledPreviewData::fromOutput($output, $r['declaration'], base64_decode($r['body_base64']), $r['metadata']);
    }

    private function rejected(callable $call, ?int $exit = null): void
    {
        try {
            $call();
            self::fail('No artifact may be returned.');
        } catch (PreviewCompilationException $e) {
            self::assertSame('Preview compilation failed.', $e->getMessage());
            self::assertSame($exit, $e->compilerExitCode);
            self::assertNull($e->getPrevious());
        }
    }

    public function test_diagnostic_outcomes_remain_data_not_qualification(): void
    {
        foreach (['failed', 'empty', 'blocked', 'ambiguous', 'incomplete'] as $validity) {
            $a = json_decode($this->artifact(), true);
            $a['preview']['validity'] = $a['preview']['wire']['validity'] = $validity;
            $result = json_encode($a['preview']['wire'], JSON_UNESCAPED_SLASHES);
            $a['preview']['result_base64'] = base64_encode($result);
            $a['preview']['result_sha256'] = hash('sha256', $result);
            self::assertSame($validity, $this->decode(json_encode($a))->validity);
        }
    }

    public function test_large_outer_capture_string_decodes_through_public_api(): void
    {
        $r = $this->request();
        $body = str_repeat('synthetic body ', 6000);
        $a = json_decode($this->artifact(), true, 32, JSON_THROW_ON_ERROR);
        $a['capture']['body_base64'] = base64_encode($body);
        $a['capture']['content_sha256'] = hash('sha256', $body);
        self::assertSame('incomplete', CompiledPreviewData::fromOutput(json_encode($a, JSON_THROW_ON_ERROR), $r['declaration'], $body, $r['metadata'])->validity);
    }

    public function test_outer_scanner_rejects_escaped_duplicates_after_large_capture(): void
    {
        $r = $this->request();
        $body = str_repeat('synthetic body ', 6000);
        $a = json_decode($this->artifact(), true, 32, JSON_THROW_ON_ERROR);
        $a['capture']['body_base64'] = base64_encode($body);
        $a['capture']['content_sha256'] = hash('sha256', $body);
        $output = json_encode($a, JSON_THROW_ON_ERROR);
        $this->rejected(fn () => CompiledPreviewData::fromOutput(substr($output, 0, -1).',"format_vers\\u0069on":1}', $r['declaration'], $body, $r['metadata']));
        $output = str_replace('"encoding":"utf-8"', '"encoding":"utf-8","encod\\u0069ng":"utf-8"', $output);
        self::assertStringContainsString('encod\\u0069ng', $output);
        $this->rejected(fn () => CompiledPreviewData::fromOutput($output, $r['declaration'], $body, $r['metadata']));
    }

    private function evidenceArtifact(string $evidence): array
    {
        $a = json_decode($this->artifact(), true, 32, JSON_THROW_ON_ERROR);
        $a['preview']['wire']['evidence'] = $evidence;
        return $a;
    }

    private function withResult(array $a, ?string $result = null): string
    {
        $result ??= json_encode($a['preview']['wire'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $a['preview']['result_base64'] = base64_encode($result);
        $a['preview']['result_sha256'] = hash('sha256', $result);
        return json_encode($a, JSON_THROW_ON_ERROR);
    }

    public function test_large_producer_evidence_decodes_through_public_api(): void
    {
        // Include repeated escapes as well as long plain runs, without private/live fixtures.
        $evidence = str_repeat('synthetic evidence ', 4000).' '.str_repeat("\"\\\nñ", 4000)."\x7f";
        $a = $this->evidenceArtifact($evidence);
        // Match Python ensure_ascii's DEL spelling (PHP otherwise leaves it literal).
        $result = str_replace("\x7f", '\\u007f', json_encode($a['preview']['wire'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $data = $this->decode($this->withResult($a, $result));
        self::assertSame($evidence, $data->wire['evidence']);
        self::assertSame($result, $data->resultBytes);
        self::assertSame(hash('sha256', $result), $data->resultSha256);
    }

    public function test_long_evidence_does_not_hide_late_escaped_duplicates_or_lexical_changes(): void
    {
        $a = $this->evidenceArtifact(str_repeat('evidence ', 8000));
        $output = $this->withResult($a);
        $result = json_encode($a['preview']['wire'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach ([
            substr($result, 0, -1).',"eviden\\u0063e":"duplicate"}',
            substr($result, 0, -1).',"extra":[{"key":1,"k\\u0065y":2}]}',
            str_replace('evidence ', '\\u0065vidence ', $result),
            $result.' ',
        ] as $badResult) {
            $this->rejected(fn () => $this->decode($this->withResult($a, $badResult)));
        }
        // Outer grammar allows whitespace/escaped keys, but not duplicates after a long value.
        $this->rejected(fn () => $this->decode(substr($output, 0, -1).',"previ\\u0065w":{}}'));
        $this->rejected(fn () => $this->decode(substr($output, 0, -1).',"extra":{"key":1,"k\\u0065y":2}}'));
        $this->rejected(fn () => $this->decode($output.'{}'));
    }

    public function test_decoder_rejects_malformed_duplicate_trailing_and_oversized_json(): void
    {
        foreach (['', '{}', $this->artifact().'{}', str_replace('"format_version":1', '"format_version":1,"format_vers\\u0069on":1', $this->artifact()), str_repeat(' ', ProcessPreviewCompiler::MAX_OUTPUT + 1), str_replace('"format_version":1', '"format_version":NaN', $this->artifact())] as $output) {
            $this->rejected(fn () => $this->decode($output));
        }
    }

    public function test_decoder_rejects_unknown_types_identity_and_integrity_mismatches(): void
    {
        $changes = [
            ['format_version', '1'], ['approved', true], ['format_version', 1.1],
            ['configuration.declaration.source', 'other'], ['configuration.revision', 'bad'],
            ['configuration.canonical_base64', 'e30='], ['capture.body_base64', 'YQ=='],
            ['capture.content_sha256', 'bad'], ['capture.metadata.status', '200'],
            ['capture.metadata.observed_at', '2026-10-18T13:00:00Z'],
            ['capture.metadata.encoding', 'latin-1'], ['capture.metadata.origin_verified', true],
            ['preview.validity', 'valid'], ['preview.result_sha256', 'bad'],
            ['preview.result_base64', 'e30='], ['preview.wire.positions', new \stdClass()],
            ['preview.wire.approved', true],
        ];
        foreach ($changes as [$path, $value]) {
            $a = json_decode($this->artifact(), true, 32, JSON_THROW_ON_ERROR);
            $node = &$a;
            foreach (explode('.', $path) as $key) {
                $node = &$node[$key];
            }
            $node = $value;
            unset($node);
            $this->rejected(fn () => $this->decode(json_encode($a, JSON_THROW_ON_ERROR)));
        }
        $a = json_decode($this->artifact(), true);
        $a['capture']['body_base64'] .= "\n"; // PHP strict decode permits whitespace; our decoder must not.
        $this->rejected(fn () => $this->decode(json_encode($a)));
    }

    public function test_result_inner_duplicate_unknown_and_impossible_qualification_are_rejected_even_with_updated_hashes(): void
    {
        foreach (['{"source":"x","source":"x"}', '{"x":1e999}', '{"x":1.0}'] as $result) {
            $a = json_decode($this->artifact(), true);
            $a['preview']['result_base64'] = base64_encode($result);
            $a['preview']['result_sha256'] = hash('sha256', $result);
            $this->rejected(fn () => $this->decode(json_encode($a)));
        }
        $a = json_decode($this->artifact(), true);
        $a['preview']['validity'] = $a['preview']['wire']['validity'] = 'valid';
        $result = json_encode($a['preview']['wire']);
        $a['preview']['result_base64'] = base64_encode($result);
        $a['preview']['result_sha256'] = hash('sha256', $result);
        $this->rejected(fn () => $this->decode(json_encode($a)));
    }

    public function test_producer_bytes_reject_noncanonical_forms_even_when_all_hashes_match(): void
    {
        foreach (['canonical-space', 'canonical-order', 'canonical-escape', 'result-space', 'result-order'] as $mode) {
            $a = json_decode($this->artifact(), true);
            $canonical = base64_decode($a['configuration']['canonical_base64']);
            if ($mode === 'canonical-space') {
                $canonical = ' '.$canonical;
            } elseif ($mode === 'canonical-order') {
                $identity = json_decode($canonical, true);
                $canonical = json_encode(array_reverse($identity, true), JSON_UNESCAPED_SLASHES);
            } elseif ($mode === 'canonical-escape') {
                $canonical = str_replace('/', '\\/', $canonical);
            }
            $a['configuration']['canonical_base64'] = base64_encode($canonical);
            $revision = 'configured-html-v1:'.hash('sha256', $canonical);
            $a['configuration']['revision'] = $a['preview']['wire']['revision'] = $revision;
            $wire = $a['preview']['wire'];
            if ($mode === 'result-order') {
                $wire = array_reverse($wire, true);
            }
            $result = ($mode === 'result-space' ? ' ' : '').json_encode($wire, JSON_UNESCAPED_SLASHES);
            $a['preview']['result_base64'] = base64_encode($result);
            $a['preview']['result_sha256'] = hash('sha256', $result);
            $this->rejected(fn () => $this->decode(json_encode($a)));
        }
    }

    public function test_wrong_type_is_rejected_even_when_request_and_all_byte_identities_agree(): void
    {
        $r = $this->request();
        $r['declaration']['source'] = 12;
        $a = json_decode($this->artifact(), true);
        $a['configuration']['declaration'] = $r['declaration'];
        $identity = json_decode(base64_decode($a['configuration']['canonical_base64']), true);
        $identity['config']['source'] = 12;
        $canonical = json_encode($identity, JSON_UNESCAPED_SLASHES);
        $a['configuration']['canonical_base64'] = base64_encode($canonical);
        $a['configuration']['revision'] = $a['preview']['wire']['revision'] = 'configured-html-v1:'.hash('sha256', $canonical);
        $a['preview']['wire']['source'] = 12;
        $result = json_encode($a['preview']['wire'], JSON_UNESCAPED_SLASHES);
        $a['preview']['result_base64'] = base64_encode($result);
        $a['preview']['result_sha256'] = hash('sha256', $result);
        $this->rejected(fn () => CompiledPreviewData::fromOutput(json_encode($a), $r['declaration'], base64_decode($r['body_base64']), $r['metadata']));
    }

    public function test_role_condition_declaration_and_identity_must_agree_and_be_valid(): void
    {
        foreach (['ai_suffix', null, true, 1, [], 'other', 'conflict'] as $option) {
            $r = $this->request();
            $r['declaration']['role_condition'] = $option === 'conflict' ? 'ai_suffix' : $option;
            if ($option === 'conflict') {
                $r['declaration']['fields']['condition'] = ['column' => 0];
            }
            $a = json_decode($this->artifact(), true);
            $a['configuration']['declaration'] = $r['declaration'];
            $identity = json_decode(base64_decode($a['configuration']['canonical_base64']), true);
            $identity['config']['role_condition'] = $r['declaration']['role_condition'];
            ksort($identity['config']);
            $canonical = json_encode($identity, JSON_UNESCAPED_SLASHES);
            $a['configuration']['canonical_base64'] = base64_encode($canonical);
            $a['configuration']['revision'] = $a['preview']['wire']['revision'] = 'configured-html-v1:'.hash('sha256', $canonical);
            $output = $this->withResult($a);
            $decode = fn () => CompiledPreviewData::fromOutput($output, $r['declaration'], base64_decode($r['body_base64']), $r['metadata']);
            if ($option === 'ai_suffix') {
                self::assertSame('incomplete', $decode()->validity);
                $this->rejected(fn () => $this->decode($output));
            } else {
                $this->rejected($decode);
            }
        }
    }

    public function test_timestamp_normalization_accepts_same_instant_with_offset(): void
    {
        $r = $this->request();
        $r['metadata']['observed_at'] = '2026-10-18T08:00:00-04:00';
        self::assertSame('incomplete', CompiledPreviewData::fromOutput($this->artifact(), $r['declaration'], base64_decode($r['body_base64']), $r['metadata'])->validity);
    }

    private function adapter(\Closure $run): ProcessPreviewCompiler
    {
        return new ProcessPreviewCompiler('/server/python', function (array $argv, string $cwd, string $input, float $timeout) use ($run): array {
            $root = dirname(__DIR__, 3);
            self::assertSame(['/server/python', '-E', '-s', $root.'/scripts/website_monitor_pro/pep_directory_preview.py'], $argv);
            self::assertSame($root, $cwd);
            self::assertSame(10.0, $timeout);
            self::assertSame($this->request(), json_decode($input, true));
            return $run();
        });
    }

    private function compile(ProcessPreviewCompiler $adapter): CompiledPreviewData
    {
        $r = $this->request();
        return $adapter->compile($r['declaration'], base64_decode($r['body_base64']), $r['metadata']);
    }

    public function test_fake_transport_receives_fixed_contract_and_complete_output(): void
    {
        $calls = 0;
        $runner = function () use (&$calls): array {
            $calls++;
            return ['stdout' => $this->artifact(), 'stderrBytes' => 0, 'exitCode' => 0];
        };
        self::assertSame('incomplete', $this->compile($this->adapter($runner))->validity);
        self::assertSame(1, $calls, 'No retries.');
    }

    public function test_fake_transport_failure_nonzero_and_incomplete_output_return_no_artifact(): void
    {
        foreach (['nonzero', 'timeout', 'failure', 'partial', 'trailing'] as $mode) {
            $runner = function () use ($mode): array {
                if ($mode === 'timeout') {
                    throw new PreviewCompilationException(137);
                }
                if ($mode === 'failure') {
                    throw new \RuntimeException('SECRET body argv stderr');
                }
                return ['stdout' => $mode === 'partial' ? '{' : $this->artifact().($mode === 'trailing' ? '{}' : ''), 'stderrBytes' => 0, 'exitCode' => $mode === 'nonzero' ? 2 : 0];
            };
            $this->rejected(fn () => $this->compile($this->adapter($runner)), match ($mode) {
                'nonzero' => 2, 'timeout' => 137, 'partial', 'trailing' => 0, default => null,
            });
        }
    }

    public function test_input_limits_reject_before_factory_and_no_fallback(): void
    {
        $calls = 0;
        $factory = function () use (&$calls): array { $calls++; return []; };
        foreach ([null, '', 'python', '/server/python'] as $interpreter) {
            $this->rejected(fn () => (new ProcessPreviewCompiler($interpreter, $factory))->compile([], str_repeat('x', ProcessPreviewCompiler::MAX_BODY + 1), []));
        }
        $this->rejected(fn () => (new ProcessPreviewCompiler('/server/python', $factory))->compile(['x' => str_repeat('x', ProcessPreviewCompiler::MAX_INPUT)], '', []));
        foreach ([null, '', 'python'] as $interpreter) {
            $this->rejected(fn () => $this->compile(new ProcessPreviewCompiler($interpreter, $factory)));
        }
        self::assertSame(0, $calls, 'Input rejection must occur before the transport seam.');
    }

    public function test_missing_interpreter_is_sanitized(): void
    {
        if (! class_exists(ProcessPreviewCompiler::class)) {
            self::fail('Unavailable interpreter must fail through the adapter domain API.');
        }
        $this->expectException(PreviewCompilationException::class);
        $this->expectExceptionMessage('Preview compilation failed.');
        (new ProcessPreviewCompiler())->compile([], '', []);
    }
}
