<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return; // SQLite uses dynamic typing, ENUM constraint not needed
        }

        if ($driver === 'pgsql') {
            // PostgreSQL: add check constraint for new enum value
            DB::statement('ALTER TABLE log_scripts DROP CONSTRAINT IF EXISTS log_scripts_estado_check');
            DB::statement("ALTER TABLE log_scripts ADD CONSTRAINT log_scripts_estado_check CHECK (estado IN ('iniciado','completado','error','interrumpido'))");

            return;
        }

        // MySQL/MariaDB
        DB::statement("ALTER TABLE log_scripts MODIFY COLUMN estado ENUM('iniciado','completado','error','interrumpido') NOT NULL DEFAULT 'iniciado'");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            DB::statement("UPDATE log_scripts SET estado = 'error' WHERE estado = 'interrumpido'");
            DB::statement('ALTER TABLE log_scripts DROP CONSTRAINT IF EXISTS log_scripts_estado_check');
            DB::statement("ALTER TABLE log_scripts ADD CONSTRAINT log_scripts_estado_check CHECK (estado IN ('iniciado','completado','error'))");

            return;
        }

        // MySQL/MariaDB
        DB::statement("UPDATE log_scripts SET estado = 'error' WHERE estado = 'interrumpido'");
        DB::statement("ALTER TABLE log_scripts MODIFY COLUMN estado ENUM('iniciado','completado','error') NOT NULL DEFAULT 'iniciado'");
    }
};
