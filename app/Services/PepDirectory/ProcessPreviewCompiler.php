<?php

declare(strict_types=1);

namespace App\Services\PepDirectory;

use App\Exceptions\PepDirectory\PreviewCompilationException;
use App\Services\PepDirectory\DTOs\CompiledPreviewData;

final class ProcessPreviewCompiler implements PreviewCompiler
{
    public const MAX_INPUT = 2_097_152;
    public const MAX_BODY = 1_048_576;
    public const MAX_OUTPUT = 8_388_608;
    public const MAX_STDERR = 8_192;
    public const TIMEOUT = 10.0;

    /** Internal runner seam: never resolve executable or runner from request data. */
    public function __construct(private readonly ?string $interpreter = null, private readonly ?\Closure $runner = null) {}

    public function compile(array $declaration, string $rawBody, array $captureMetadata): CompiledPreviewData
    {
        $exit = null;
        try {
            if ($this->interpreter === null || ! str_starts_with($this->interpreter, '/') || str_contains($this->interpreter, "\0") || strlen($rawBody) > self::MAX_BODY) {
                throw new PreviewCompilationException();
            }
            $input = json_encode(['declaration' => $declaration, 'body_base64' => base64_encode($rawBody), 'metadata' => $captureMetadata], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if (strlen($input) > self::MAX_INPUT) {
                throw new PreviewCompilationException();
            }
            $root = dirname(__DIR__, 3);
            $argv = [$this->interpreter, '-E', '-s', $root.'/scripts/website_monitor_pro/pep_directory_preview.py'];
            $result = $this->runner === null
                ? (new BoundedProcessTransport())->run($argv, $root, $input, self::TIMEOUT)
                : ($this->runner)($argv, $root, $input, self::TIMEOUT);
            $exit = $result['exitCode'];
            if ($exit !== 0) {
                throw new PreviewCompilationException($exit);
            }
            return CompiledPreviewData::fromOutput($result['stdout'], $declaration, $rawBody, $captureMetadata);
        } catch (PreviewCompilationException $e) {
            throw new PreviewCompilationException($exit ?? $e->compilerExitCode);
        } catch (\Throwable) {
            throw new PreviewCompilationException($exit);
        }
    }
}
