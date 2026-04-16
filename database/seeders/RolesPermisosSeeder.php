<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesPermisosSeeder extends Seeder
{
    public function run(): void
    {
        // Limpiar cache de permisos
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // ── Definicion de permisos ──────────────────────────────────────
        $permisos = [
            // Dashboard
            'ver dashboard',
            'ver dashboard estadisticas',

            // Scraper — lectura y acciones de usuario
            'ver resultados scraper',
            'marcar leido',
            'marcar relevante',
            'exportar csv scraper',

            // Scraper — gestion (CRUD sitios y keywords)
            'gestionar sitios',
            'gestionar keywords',
            'gestionar familias lemas',
            'gestionar cargos pep',
            'gestionar entidades publicas',

            // Scraper — feedback de clasificaciones Gemini
            'dar feedback clasificaciones',

            // PEP Monitor — lectura y acciones
            'ver cambios pep',
            'marcar revisado pep',

            // PEP Monitor — gestion (CRUD fuentes)
            'gestionar fuentes',

            // Scripts
            'ver estado scripts',
            'configurar scripts',

            // Usuarios
            'gestionar usuarios',
        ];

        foreach ($permisos as $permiso) {
            Permission::firstOrCreate(['name' => $permiso]);
        }

        // ── Roles ────────────────────────────────────────────────────────

        // ADMIN — acceso total
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions($permisos);

        // SUPERVISOR — todo menos gestion de usuarios
        $supervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $supervisor->syncPermissions([
            'ver dashboard',
            'ver dashboard estadisticas',
            'ver resultados scraper',
            'marcar leido',
            'marcar relevante',
            'exportar csv scraper',
            'gestionar sitios',
            'gestionar keywords',
            'dar feedback clasificaciones',
            'ver cambios pep',
            'marcar revisado pep',
            'gestionar fuentes',
            'ver estado scripts',
            'configurar scripts',
        ]);

        // OPERADOR — solo lectura y acciones basicas, sin CRUD ni config
        $operador = Role::firstOrCreate(['name' => 'operador']);
        $operador->syncPermissions([
            'ver dashboard',
            'ver resultados scraper',
            'marcar leido',
            'marcar relevante',
            'exportar csv scraper',
            'ver cambios pep',
            'marcar revisado pep',
            'ver estado scripts',
        ]);

        // ── Usuarios admin ─────────────────────────────────────

        // Admin local (desarrollo)
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@simo.local'],
            [
                'name' => 'Administrador',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'changeme')),
                'activo' => true,
            ]
        );
        $adminUser->syncRoles(['admin']);

        // Admin producción
        $siriUser = User::firstOrCreate(
            ['email' => 'siri@email.com'],
            [
                'name' => 'Siri Admin',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'changeme')),
                'activo' => true,
            ]
        );
        $siriUser->syncRoles(['admin']);

        $this->command->info('Roles, permisos y usuarios admin creados correctamente.');
        $this->command->info('  Login desarrollo: admin@simo.local / password');
        $this->command->info('  Login producción: siri@email.com / siri0213');
    }
}
