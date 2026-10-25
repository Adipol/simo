<?php

declare(strict_types=1);

namespace App\Services\PepDirectory;

use App\Exceptions\PepDirectory\CaptureException;

/** Dormant internal adapter; interpreter availability never enables network. */
final class ProcessCaptureTransport
{
    public function __construct(private readonly ?string $interpreter = null, private readonly bool $enabled = false, private readonly ?\Closure $runner = null) {}

    public function capture(string $url): DTOs\CapturedPageData
    {
        $exit = null;
        try {
            if (! $this->enabled || $this->interpreter === null || ! str_starts_with($this->interpreter, '/') || str_contains($this->interpreter, "\0")
                || strlen($url) > 2048 || preg_match('~\Ahttps?://[^\s\x00-\x1f\x7f]+\z~D', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new CaptureException();
            }
            $parts = parse_url($url);
            if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
                throw new CaptureException();
            }
            $input = json_encode(['url' => $url], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (strlen($input) > 4096) {
                throw new CaptureException();
            }
            $root = dirname(__DIR__, 3);
            $argv = [$this->interpreter, '-E', '-s', $root.'/scripts/website_monitor_pro/pep_directory_capture.py'];
            $result = $this->runner === null
                ? (new BoundedProcessTransport())->run($argv, $root, $input, ProcessPreviewCompiler::TIMEOUT)
                : ($this->runner)($argv, $root, $input, ProcessPreviewCompiler::TIMEOUT);
            if (! is_array($result) || ! isset($result['exitCode'], $result['stdout']) || ! is_int($result['exitCode']) || ! is_string($result['stdout'])) {
                throw new CaptureException();
            }
            $exit = $result['exitCode'];
            if ($exit !== 0) {
                throw new CaptureException($exit);
            }
            return DTOs\CapturedPageData::fromOutput($result['stdout'], $url);
        } catch (\Throwable) {
            throw new CaptureException($exit);
        }
    }
}
