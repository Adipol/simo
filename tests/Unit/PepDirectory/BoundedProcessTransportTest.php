<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use App\Exceptions\PepDirectory\PreviewCompilationException;
use App\Services\PepDirectory\BoundedProcessTransport;
use App\Services\PepDirectory\ProcessPreviewCompiler;
use PHPUnit\Framework\TestCase;

final class BoundedProcessTransportTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../Fixtures/PepDirectory/preview-compiler/';

    protected function setUp(): void
    {
        self::assertTrue(class_exists(BoundedProcessTransport::class), 'A real bounded transport API is required.');
    }

    private function runFixture(string $name, string $input = '', float $timeout = 2.0): array
    {
        return (new BoundedProcessTransport())->run([PHP_BINARY, self::FIXTURES.$name.'.php'], dirname(__DIR__, 3), $input, $timeout);
    }

    public function test_real_success_and_nonzero_preserve_exit_output_and_stderr_count(): void
    {
        self::assertSame(['stdout' => 'synthetic input', 'stderrBytes' => 10, 'exitCode' => 0], $this->runFixture('success', 'synthetic input'));
        self::assertSame(['stdout' => 'partial', 'stderrBytes' => 6, 'exitCode' => 7], $this->runFixture('nonzero'));
    }

    public function test_duplex_large_input_drains_both_outputs_without_deadlock(): void
    {
        $input = str_repeat('synthetic-', 32_768);
        $r = $this->runFixture('duplex', $input);
        self::assertSame($input, $r['stdout']);
        self::assertSame(0, $r['exitCode']);
        self::assertGreaterThan(0, $r['stderrBytes']);
        self::assertLessThanOrEqual(ProcessPreviewCompiler::MAX_STDERR, $r['stderrBytes']);
    }

    public function test_exact_output_and_stderr_caps_are_accepted(): void
    {
        $r = $this->runFixture('exact-caps');
        self::assertSame(ProcessPreviewCompiler::MAX_OUTPUT, strlen($r['stdout']));
        self::assertSame(ProcessPreviewCompiler::MAX_STDERR, $r['stderrBytes']);
        self::assertSame(0, $r['exitCode']);
    }

    public function test_real_overflow_timeout_and_early_stdin_close_return_no_partial_result(): void
    {
        foreach (['stdout-overflow', 'stderr-overflow', 'slow', 'early-close'] as $name) {
            $start = hrtime(true);
            try {
                $this->runFixture($name, $name === 'early-close' ? str_repeat('x', 1_048_576) : '', $name === 'slow' ? 0.1 : 2.0);
                self::fail('Rejected transport must not return partial output.');
            } catch (PreviewCompilationException $e) {
                self::assertSame('Preview compilation failed.', $e->getMessage());
                self::assertNull($e->getPrevious());
                if ($name === 'slow') {
                    self::assertSame(137, $e->compilerExitCode, 'Direct fixture child must be killed and reaped.');
                }
            }
            self::assertLessThan(3.0, (hrtime(true) - $start) / 1e9);
        }
    }

    public function test_input_overflow_and_invalid_deadlines_fail_before_launch(): void
    {
        foreach ([0.0, -1.0, INF, NAN, 11.0] as $timeout) {
            try {
                $this->runFixture('success', '', $timeout);
                self::fail('Deadline must be finite and bounded.');
            } catch (PreviewCompilationException $e) {
                self::assertSame('Preview compilation failed.', $e->getMessage());
            }
        }
        $this->expectException(PreviewCompilationException::class);
        $this->runFixture('success', str_repeat('x', ProcessPreviewCompiler::MAX_INPUT + 1));
    }
}
