<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Test-only additions to the owned populated target; never a production migration. */
return new class extends Migration
{
    private bool $addedName = false;

    public function up(): void
    {
        foreach (config('permission.table_names') as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException('Authorization additions must be absent before this test.');
            }
        }
        if (Schema::hasColumn('users', 'activo')) {
            throw new RuntimeException('Test authorization column already exists.');
        }
        $this->addedName = ! Schema::hasColumn('users', 'name');
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('activo')->default(false);
            if ($this->addedName) {
                $table->string('name')->nullable();
            }
        });
        (require '/work/database/migrations/2026_03_03_193534_create_permission_tables.php')->up();
    }

    public function introducedMetadata(): array
    {
        return ['tables' => array_values(config('permission.table_names')),
            'users_columns' => $this->addedName ? ['activo', 'name'] : ['activo']];
    }

    public function down(): void
    {
        (require '/work/database/migrations/2026_03_03_193534_create_permission_tables.php')->down();
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn($this->addedName ? ['activo', 'name'] : ['activo']);
        });
    }
};
