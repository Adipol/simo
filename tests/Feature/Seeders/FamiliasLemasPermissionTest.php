<?php

declare(strict_types=1);

namespace Tests\Feature\Seeders;

use Database\Seeders\RolesPermisosSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FamiliasLemasPermissionTest extends TestCase
{
    use RefreshDatabase;

    private const PERMISSION = 'gestionar familias lemas';

    public function test_permission_does_not_exist_before_seeding(): void
    {
        $exists = Permission::where('name', self::PERMISSION)->exists();

        $this->assertFalse($exists);
    }

    public function test_admin_role_has_permission_after_seeding(): void
    {
        $this->seed(RolesPermisosSeeder::class);

        $admin = Role::findByName('admin');

        $this->assertTrue($admin->hasPermissionTo(self::PERMISSION));
    }

    public function test_supervisor_role_does_not_have_permission(): void
    {
        $this->seed(RolesPermisosSeeder::class);

        $supervisor = Role::findByName('supervisor');

        $this->assertFalse($supervisor->hasPermissionTo(self::PERMISSION));
    }

    public function test_operador_role_does_not_have_permission(): void
    {
        $this->seed(RolesPermisosSeeder::class);

        $operador = Role::findByName('operador');

        $this->assertFalse($operador->hasPermissionTo(self::PERMISSION));
    }
}
