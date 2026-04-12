<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DashboardEstadisticasPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'ver dashboard estadisticas';

    // 5.1 Permission does NOT exist before seeding

    public function test_permission_does_not_exist_before_seeding(): void
    {
        $exists = Permission::where('name', self::PERMISSION)->exists();

        $this->assertFalse($exists);
    }

    // 5.2 Admin and supervisor HAVE permission after seeding

    public function test_admin_role_has_permission_after_seeding(): void
    {
        $this->seed(RolesPermisosSeeder::class);

        $admin = Role::findByName('admin');

        $this->assertTrue($admin->hasPermissionTo(self::PERMISSION));
    }

    public function test_supervisor_role_has_permission_after_seeding(): void
    {
        $this->seed(RolesPermisosSeeder::class);

        $supervisor = Role::findByName('supervisor');

        $this->assertTrue($supervisor->hasPermissionTo(self::PERMISSION));
    }

    // 5.3 Operador does NOT have permission

    public function test_operador_role_does_not_have_permission_after_seeding(): void
    {
        $this->seed(RolesPermisosSeeder::class);

        $operador = Role::findByName('operador');

        $this->assertFalse($operador->hasPermissionTo(self::PERMISSION));
    }
}
