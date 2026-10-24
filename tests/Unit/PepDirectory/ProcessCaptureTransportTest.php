<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use App\Exceptions\PepDirectory\CaptureException;
use App\Services\PepDirectory\ProcessCaptureTransport;
use PHPUnit\Framework\TestCase;

final class ProcessCaptureTransportTest extends TestCase
{
    private const URL = 'http://directory.example/people';

    private function artifact(string $url = self::URL): array
    {
        $secure = str_starts_with($url, 'https://');
        return ['format_version' => 1, 'requested_url' => $url, 'final_url' => $url,
            'status' => 200, 'encoding' => 'utf-8', 'captured_at' => '2026-10-18T12:00:00+00:00',
            'body_base64' => base64_encode("<html>People</html>\0"), 'content_sha256' => hash('sha256', "<html>People</html>\0"),
            'transport' => ['adapter' => $secure ? 'public-https-pinned-v1' : 'public-http-pinned-v1',
                'peer_ip' => '93.184.216.34', 'tls_verified' => $secure]];
    }

    private function encodeArtifact(array $artifact): string
    {
        return json_encode($artifact, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
    }

    private function processResult(string $stdout, int $exit = 0): array
    {
        return ['stdout' => $stdout, 'stderrBytes' => 0, 'exitCode' => $exit];
    }

    private function refused(callable $call, ?int $exit = null): void
    {
        try {
            $call();
            self::fail('Capture must not be returned.');
        } catch (CaptureException $error) {
            self::assertSame('Directory capture failed.', $error->getMessage());
            self::assertSame($exit, $error->captureExitCode);
            self::assertNull($error->getPrevious());
        }
    }

    public function test_enabled_adapter_accepts_complete_synthetic_capture_with_fixed_invocation(): void
    {
        foreach ([self::URL, str_replace('http:', 'https:', self::URL)] as $url) {
            $calls = 0;
            $adapter = new ProcessCaptureTransport('/usr/bin/python3', true, function (array $argv, string $cwd, string $input, float $timeout) use ($url, &$calls): array {
                $calls++;
                self::assertSame(['/usr/bin/python3', '-E', '-s', $cwd.'/scripts/website_monitor_pro/pep_directory_capture.py'], $argv);
                self::assertSame(realpath(__DIR__.'/../../..'), $cwd);
                self::assertSame(['url' => $url], json_decode($input, true));
                self::assertSame(10.0, $timeout);
                return $this->processResult($this->encodeArtifact($this->artifact($url)));
            });
            $data = $adapter->capture($url);
            self::assertSame(1, $calls);
            self::assertSame("<html>People</html>\0", $data->capture->body);
            self::assertSame($url, $data->capture->requestedUrl);
            self::assertSame($url, $data->capture->finalUrl);
            self::assertSame('93.184.216.34', $data->peerIp);
            self::assertSame(str_starts_with($url, 'https:'), $data->tlsVerified);
            self::assertSame(['adapter', 'captured_at_input'], array_keys($data->capture->provenance));
        }
    }

    public function test_disabled_or_invalid_interpreter_never_calls_runner(): void
    {
        $calls = 0;
        $runner = function () use (&$calls): array { $calls++; return []; };
        foreach ([new ProcessCaptureTransport('/usr/bin/python3', runner: $runner), new ProcessCaptureTransport(null, true, $runner), new ProcessCaptureTransport('python3', true, $runner), new ProcessCaptureTransport("/bad\0path", true, $runner)] as $adapter) {
            $this->refused(fn () => $adapter->capture(self::URL));
        }
        self::assertSame(0, $calls);
    }

    public function test_invalid_request_never_reaches_runner(): void
    {
        $calls = 0;
        $adapter = new ProcessCaptureTransport('/usr/bin/python3', true, function () use (&$calls): array { $calls++; return []; });
        foreach (['file:///etc/passwd', 'http://user:pass@directory.example/a', "http://directory.example/\r\nx", 'http://directory.example/a#fragment', str_repeat('x', 2049)] as $url) {
            $this->refused(fn () => $adapter->capture($url));
        }
        self::assertSame(0, $calls);
    }

    public function test_failed_or_signalled_process_never_returns_partial_output_or_retries(): void
    {
        foreach ([2, 142, -14] as $exit) {
            $calls = 0;
            $adapter = new ProcessCaptureTransport('/usr/bin/python3', true, function () use ($exit, &$calls): array {
                $calls++;
                return $this->processResult($this->encodeArtifact($this->artifact()), $exit);
            });
            $this->refused(fn () => $adapter->capture(self::URL), $exit);
            self::assertSame(1, $calls);
        }
        $adapter = new ProcessCaptureTransport('/usr/bin/python3', true, fn () => throw new \RuntimeException('secret path'));
        $this->refused(fn () => $adapter->capture(self::URL));
    }

    public function test_decoder_refuses_malformed_duplicate_trailing_and_oversized_output(): void
    {
        $valid = $this->encodeArtifact($this->artifact());
        foreach (['', '{}', $valid.'{}', str_replace('"format_version":1', '"format_version":1,"format_vers\\u0069on":1', $valid), str_repeat(' ', 2097153)] as $output) {
            $adapter = new ProcessCaptureTransport('/usr/bin/python3', true, fn () => $this->processResult($output));
            $this->refused(fn () => $adapter->capture(self::URL), 0);
        }
    }

    public function test_decoder_refuses_shape_url_integrity_and_transport_mismatches(): void
    {
        $changes = [['format_version', '1'], ['extra', true], ['requested_url', 'http://other.example/'], ['final_url', 'https://directory.example/people'], ['body_base64', 'eA=='], ['content_sha256', 'bad'], ['captured_at', '2026-02-30T12:00:00+00:00'], ['captured_at', '2026-10-18T12:00:00'], ['status', '200'], ['status', 302], ['encoding', "bad\0"], ['transport', ['adapter' => 'public-http-pinned-v1', 'peer_ip' => 'invalid', 'tls_verified' => false]], ['transport', ['adapter' => 'public-http-pinned-v1', 'peer_ip' => '93.184.216.34', 'tls_verified' => true]]];
        foreach ($changes as [$key, $value]) {
            $a = $this->artifact();
            $a[$key] = $value;
            $adapter = new ProcessCaptureTransport('/usr/bin/python3', true, fn () => $this->processResult($this->encodeArtifact($a)));
            $this->refused(fn () => $adapter->capture(self::URL), 0);
        }
    }
}
