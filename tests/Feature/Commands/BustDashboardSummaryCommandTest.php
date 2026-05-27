<?php

declare(strict_types=1);

namespace Tests\Feature\Commands;

use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class BustDashboardSummaryCommandTest extends TestCase
{
    public function test_command_limpia_la_clave_de_cache_y_retorna_exito(): void
    {
        Cache::put('dashboard:summary', 'cached-value', 3600);
        $this->assertNotNull(Cache::get('dashboard:summary'));

        $this->artisan('dashboard:summary:bust')
            ->expectsOutputToContain('✓ Dashboard summary cache busted.')
            ->assertExitCode(0);

        $this->assertNull(Cache::get('dashboard:summary'));
    }

    public function test_command_es_idempotente_cuando_cache_ya_esta_vacia(): void
    {
        $this->assertNull(Cache::get('dashboard:summary'));

        $this->artisan('dashboard:summary:bust')
            ->assertExitCode(0);

        $this->assertNull(Cache::get('dashboard:summary'));
    }
}
