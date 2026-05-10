<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;

final class DashboardCacheManager
{
    public const KEY_PREFIX = 'dashboard:';

    /**
     * Retrieve a value from cache or compute it via the callback.
     *
     * @param \Closure(): mixed $callback
     */
    public function remember(string $key, int $ttl, \Closure $callback): mixed
    {
        return Cache::remember($key, $ttl, $callback);
    }

    /**
     * Remove a single cache key.
     */
    public function forget(string $key): void
    {
        Cache::forget($key);
    }

    /**
     * Remove all cache keys that start with the dashboard prefix.
     * Uses the registered known-keys list since the default cache stores
     * (array/file/database) do not support pattern-based deletion.
     */
    public function forgetAll(): void
    {
        foreach ($this->knownDashboardKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Build a per-user scoped key (used when cached payload differs by user).
     * For PR1.1 the payload is single + canSeeDetails flag resolved post-cache,
     * so this method is available but not called yet.
     */
    public function keyForUser(string $base, ?int $userId): string
    {
        if ($userId === null) {
            return $base;
        }

        return $base . ':user:' . $userId;
    }

    /**
     * Returns all known dashboard cache keys for bulk invalidation.
     * Extend this list when new metric keys are added.
     *
     * @return array<string>
     */
    private function knownDashboardKeys(): array
    {
        return [
            self::KEY_PREFIX . 'summary:hero',
            self::KEY_PREFIX . 'summary:triage',
            self::KEY_PREFIX . 'summary:backlog',
            self::KEY_PREFIX . 'summary:ultima',
            self::KEY_PREFIX . 'summary:recent',
            self::KEY_PREFIX . 'summary:spark',
            self::KEY_PREFIX . 'health',
            self::KEY_PREFIX . 'health:latency',
            self::KEY_PREFIX . 'health:quota',
        ];
    }
}
