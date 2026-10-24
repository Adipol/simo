<?php

declare(strict_types=1);

namespace App\Services\PepDirectory;

use App\Exceptions\PepDirectory\PreviewCompilationException;

/** Internal direct-child transport, not a sandbox or a request-selected command API. */
final class BoundedProcessTransport
{
    private const CHUNK = 8192;

    /** @return array{stdout: string, stderrBytes: int, exitCode: int} */
    public function run(array $argv, string $cwd, string $input, float $timeout): array
    {
        $process = null;
        $pipes = [];
        $exit = null;
        $failed = false;
        $stdout = '';
        $stderrBytes = 0;
        // Warnings can contain executable paths or pipe data. Never propagate them.
        set_error_handler(static function (): never { throw new \RuntimeException('Process I/O failed.'); });
        try {
            if ($argv === [] || ! array_is_list($argv) || ! is_string($argv[0]) || ! str_starts_with($argv[0], '/')
                || strlen($input) > ProcessPreviewCompiler::MAX_INPUT || ! is_finite($timeout) || $timeout <= 0 || $timeout > ProcessPreviewCompiler::TIMEOUT) {
                throw new PreviewCompilationException();
            }
            foreach ($argv as $argument) {
                if (! is_string($argument) || str_contains($argument, "\0")) {
                    throw new PreviewCompilationException();
                }
            }
            $deadline = hrtime(true) + (int) ($timeout * 1e9);
            $process = proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, null, ['bypass_shell' => true]);
            if (! is_resource($process)) {
                throw new PreviewCompilationException();
            }
            foreach ($pipes as $pipe) {
                if (! stream_set_blocking($pipe, false)) {
                    throw new PreviewCompilationException();
                }
            }
            $offset = 0;
            while (true) {
                if (hrtime(true) >= $deadline) {
                    throw new PreviewCompilationException();
                }
                $running = $this->running($process, $exit);
                if (isset($pipes[0]) && ($offset === strlen($input) || ! $running)) {
                    fclose($pipes[0]);
                    unset($pipes[0]);
                }
                if (! $running && $offset !== strlen($input)) {
                    throw new PreviewCompilationException();
                }
                if (! $running && ! isset($pipes[1]) && ! isset($pipes[2])) {
                    break;
                }
                $read = array_intersect_key($pipes, [1 => true, 2 => true]);
                $write = isset($pipes[0]) ? [$pipes[0]] : [];
                $except = [];
                $micros = max(1, min(10_000, (int) (($deadline - hrtime(true)) / 1000)));
                if ($read === [] && $write === []) {
                    usleep($micros); // Bounded direct-child reap wait after all pipes reach EOF.
                    continue;
                }
                if (stream_select($read, $write, $except, 0, $micros) === false) {
                    throw new PreviewCompilationException();
                }
                // At most one fixed-size read from each ready output per iteration.
                foreach ($read as $index => $pipe) {
                    $chunk = fread($pipe, self::CHUNK);
                    if ($chunk === false) {
                        throw new PreviewCompilationException();
                    }
                    $size = strlen($chunk);
                    if ($index === 1) {
                        if ($size > ProcessPreviewCompiler::MAX_OUTPUT - strlen($stdout)) {
                            throw new PreviewCompilationException();
                        }
                        $stdout .= $chunk;
                    } else {
                        if ($size > ProcessPreviewCompiler::MAX_STDERR - $stderrBytes) {
                            throw new PreviewCompilationException();
                        }
                        $stderrBytes += $size; // Diagnostics are counted, never retained.
                    }
                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($pipes[$index]);
                    }
                }
                if ($write !== []) {
                    $written = fwrite($pipes[0], substr($input, $offset, self::CHUNK));
                    if ($written === false || ($written === 0 && feof($pipes[0]))) {
                        throw new PreviewCompilationException();
                    }
                    $offset += $written;
                }
            }
        } catch (\Throwable) {
            $failed = true;
        } finally {
            try {
                foreach ($pipes as $pipe) {
                    fclose($pipe);
                }
                if (is_resource($process)) {
                    if ($this->running($process, $exit)) {
                        $failed = true;
                        proc_terminate($process, 9);
                        $reapDeadline = hrtime(true) + 500_000_000;
                        do {
                            $running = $this->running($process, $exit);
                            if ($running) {
                                usleep(1000);
                            }
                        } while ($running && hrtime(true) < $reapDeadline);
                        if ($running) {
                            // Never call blocking proc_close on a still-running child.
                            throw new PreviewCompilationException();
                        }
                    }
                    $closedExit = proc_close($process);
                    if ($exit === null && $closedExit >= 0) {
                        $exit = $closedExit;
                    }
                }
            } catch (\Throwable) {
                $failed = true;
            }
            restore_error_handler();
        }
        if ($failed || $exit === null) {
            throw new PreviewCompilationException($exit);
        }
        return ['stdout' => $stdout, 'stderrBytes' => $stderrBytes, 'exitCode' => $exit];
    }

    /** @param resource $process mixed: PHP has no resource parameter type. */
    private function running(mixed $process, ?int &$exit): bool
    {
        $status = proc_get_status($process);
        if (! $status['running'] && $exit === null) {
            if ($status['exitcode'] >= 0) {
                $exit = $status['exitcode'];
            } elseif ($status['signaled'] && $status['termsig'] > 0) {
                $exit = 128 + $status['termsig'];
            }
        }
        return $status['running'];
    }
}
