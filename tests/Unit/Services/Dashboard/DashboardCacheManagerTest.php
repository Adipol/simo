<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Dashboard;

use App\Services\Dashboard\DashboardCacheManager;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardCacheManagerTest extends TestCase
{
    private DashboardCacheManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->manager = new DashboardCacheManager();
    }

    // ─── remember — cache miss ────────────────────────────────────────────────

    public function test_remember_calls_callback_on_miss_and_stores_result(): void
    {
        $callbackInvoked = 0;

        $result = $this->manager->remember('dashboard:test:key', 60, function () use (&$callbackInvoked): string {
            $callbackInvoked++;

            return 'computed-value';
        });

        $this->assertSame('computed-value', $result);
        $this->assertSame(1, $callbackInvoked);
    }

    // ─── remember — cache hit ─────────────────────────────────────────────────

    public function test_remember_returns_cached_value_on_hit_without_calling_callback(): void
    {
        // Prime the cache
        $this->manager->remember('dashboard:test:key', 60, fn (): string => 'first-value');

        $callbackInvoked = 0;

        $result = $this->manager->remember('dashboard:test:key', 60, function () use (&$callbackInvoked): string {
            $callbackInvoked++;

            return 'should-not-be-returned';
        });

        $this->assertSame('first-value', $result);
        $this->assertSame(0, $callbackInvoked, 'Callback must not be called on cache hit');
    }

    // ─── forget ───────────────────────────────────────────────────────────────

    public function test_forget_removes_only_the_specified_key(): void
    {
        $this->manager->remember('dashboard:key-a', 60, fn (): string => 'value-a');
        $this->manager->remember('dashboard:key-b', 60, fn (): string => 'value-b');

        $this->manager->forget('dashboard:key-a');

        // key-a must be gone → callback fires again
        $callbackFired = false;
        $this->manager->remember('dashboard:key-a', 60, function () use (&$callbackFired): string {
            $callbackFired = true;

            return 'new-a';
        });

        $this->assertTrue($callbackFired, 'key-a should have been evicted');

        // key-b must still be cached → callback must NOT fire
        $keyBCallbackFired = false;
        $this->manager->remember('dashboard:key-b', 60, function () use (&$keyBCallbackFired): string {
            $keyBCallbackFired = true;

            return 'should-not-run';
        });

        $this->assertFalse($keyBCallbackFired, 'key-b should still be in cache');
    }

    // ─── forgetAll ────────────────────────────────────────────────────────────

    public function test_forget_all_removes_all_dashboard_keys(): void
    {
        $this->manager->remember('dashboard:summary:hero', 60, fn (): string => 'hero');
        $this->manager->remember('dashboard:summary:triage', 30, fn (): string => 'triage');
        $this->manager->remember('dashboard:health', 15, fn (): string => 'health');

        $this->manager->forgetAll();

        $heroCallbackFired  = false;
        $triageCallbackFired = false;
        $healthCallbackFired = false;

        $this->manager->remember('dashboard:summary:hero', 60, function () use (&$heroCallbackFired): string {
            $heroCallbackFired = true;

            return 'new-hero';
        });

        $this->manager->remember('dashboard:summary:triage', 30, function () use (&$triageCallbackFired): string {
            $triageCallbackFired = true;

            return 'new-triage';
        });

        $this->manager->remember('dashboard:health', 15, function () use (&$healthCallbackFired): string {
            $healthCallbackFired = true;

            return 'new-health';
        });

        $this->assertTrue($heroCallbackFired, 'dashboard:summary:hero should be evicted by forgetAll');
        $this->assertTrue($triageCallbackFired, 'dashboard:summary:triage should be evicted by forgetAll');
        $this->assertTrue($healthCallbackFired, 'dashboard:health should be evicted by forgetAll');
    }

    // ─── keyForUser ───────────────────────────────────────────────────────────

    public function test_key_for_user_appends_user_id_when_provided(): void
    {
        $key = $this->manager->keyForUser('dashboard:summary:hero', 42);

        $this->assertSame('dashboard:summary:hero:user:42', $key);
    }

    public function test_key_for_user_returns_base_key_when_user_id_is_null(): void
    {
        $key = $this->manager->keyForUser('dashboard:summary:hero', null);

        $this->assertSame('dashboard:summary:hero', $key);
    }
}
