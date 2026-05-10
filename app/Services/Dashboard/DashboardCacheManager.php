<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use Illuminate\Support\Facades\Cache;

final class DashboardCacheManager
{
    private const KEY_PREFIX = 'dashboard:';

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
     */
    public function forgetAll(): void
    {
        $store = Cache::getStore();

        // If the store supports tags, use them; otherwise flush all dashboard keys by prefix.
        // For Array/file/database stores that don't support tags, we track known keys.
        foreach ($this->knownDashboardKeys() as $key) {
            Cache::forget($key);
        }
    }

    /**
     * Build a per-user scoped key (used when cached payload differs by user).
     * For PR1.1 Phase 1 this is declared but not used in practice.
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
            'dashboard:summary:hero',
            'dashboard:summary:triage',
            'dashboard:summary:backlog',
            'dashboard:summary:ultima',
            'dashboard:summary:recent',
            'dashboard:summary:spark',
            'dashboard:health',
            'dashboard:health:latency',
            'dashboard:health:quota',
        ];
    }
}
