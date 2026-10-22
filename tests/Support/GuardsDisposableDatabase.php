<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

/** Pilot-only, configuration-only check: never resolves a database manager or PDO. */
trait GuardsDisposableDatabase
{
    /**
     * Call first in beforeRefreshingDatabase; this trait deliberately defines no lifecycle hook.
     *
     * @param array<string, mixed> $database Effective config('database'), not environment intent.
     * @param array<array-key, string|null> $transactions Actual connectionsToTransact().
     * @param array<string, mixed> $migrationOptions Actual migrateFreshUsing().
     * @param array<string, array<string, string|int>> $pins Independently supplied alias => exact identity.
     */
    final protected function assertDisposableDatabase(array $database, array $transactions, array $migrationOptions, array $pins): void
    {
        try {
            $default = $database['default'] ?? null;
            if (! is_string($default) || ! is_array($database['connections'] ?? null) || $transactions === [] || $pins === []) {
                throw new RuntimeException();
            }

            // Validate the independent allowlist itself, including unused entries.
            foreach ($pins as $alias => $pin) {
                if (! is_string($alias) || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $alias) || ! is_array($pin)) {
                    throw new RuntimeException();
                }
                $identity = $this->disposableIdentity($pin);
                if (count($identity) !== count($pin)) {
                    throw new RuntimeException();
                }
                foreach ($identity as $key => $value) {
                    if (! array_key_exists($key, $pin) || $pin[$key] !== $value) {
                        throw new RuntimeException();
                    }
                }
                $pins[$alias] = $identity;
            }

            // migrate:fresh defaults to database.default, NOT connectionsToTransact().
            $targets = [$default, $migrationOptions['--database'] ?? null, ...array_values($transactions)];
            foreach ($targets as $alias) {
                $alias ??= $default;
                if (! is_string($alias) || ! array_key_exists($alias, $pins)) {
                    throw new RuntimeException();
                }
                $raw = $database['connections'][$alias] ?? null;
                if (! is_array($raw) && ! is_string($raw)) {
                    throw new RuntimeException();
                }
                if (is_array($raw) && (array_key_exists('read', $raw) || array_key_exists('write', $raw))) {
                    throw new RuntimeException();
                }
                // Same parser/precedence as DatabaseManager::configuration(), without connection().
                $resolved = (new ConfigurationUrlParser())->parseConfiguration($raw);
                if ($this->disposableIdentity($resolved) !== $pins[$alias]) {
                    throw new RuntimeException();
                }
            }
        } catch (Throwable) {
            // Do not disclose URLs, credentials or potentially sensitive connection identities.
            throw new RuntimeException('Disposable database guard refused the effective targets.');
        }
    }

    /**
     * Intentionally restricted stock connector configuration, not a general DSN validator.
     * Unknown keys (including read/write, options and connect_via_*) fail closed.
     *
     * @param array<string, mixed> $config
     * @return array<string, string|int>
     */
    private function disposableIdentity(array $config): array
    {
        $driver = $config['driver'] ?? null;
        $keys = match ($driver) {
            'sqlite' => ['driver', 'database', 'prefix', 'foreign_key_constraints', 'busy_timeout', 'journal_mode', 'synchronous'],
            'pgsql' => ['driver', 'host', 'port', 'database', 'username', 'password', 'prefix', 'prefix_indexes', 'charset', 'sslmode'],
            default => throw new RuntimeException(),
        };
        if (array_diff(array_keys($config), $keys) !== []) {
            throw new RuntimeException();
        }
        $name = $config['database'] ?? null;
        if (! is_string($name) || $name === '') {
            throw new RuntimeException();
        }
        if ($driver === 'sqlite') {
            // No URI parameters, relative paths or path traversal; do not touch the filesystem.
            if ($name !== ':memory:' && ! preg_match('~\A/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.sqlite\z~', $name)) {
                throw new RuntimeException();
            }
            return ['driver' => 'sqlite', 'database' => $name];
        }

        $host = $config['host'] ?? null;
        $port = $config['port'] ?? null;
        if (! is_string($host) || ! preg_match('/\A[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*\z/', $host)
            || ! preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/', $name)
            || (! is_int($port) && ! is_string($port))
            || ! preg_match('/\A[1-9][0-9]{0,4}\z/', (string) $port) || (int) $port > 65535) {
            throw new RuntimeException();
        }
        // These values are interpolated into the stock PostgreSQL connector's DSN.
        if (isset($config['charset']) && (! is_string($config['charset']) || ! preg_match('/\A[A-Za-z0-9_-]+\z/', $config['charset']))) {
            throw new RuntimeException();
        }
        if (isset($config['sslmode']) && ! in_array($config['sslmode'], ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'], true)) {
            throw new RuntimeException();
        }
        return ['driver' => 'pgsql', 'host' => $host, 'port' => (int) $port, 'database' => $name];
    }
}
