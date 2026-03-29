<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE log_scripts MODIFY COLUMN estado ENUM('iniciado','completado','error','interrumpido') NOT NULL DEFAULT 'iniciado'");
    }

    public function down(): void
    {
        // Convertir 'interrumpido' a 'error' antes de quitar el valor del ENUM
        DB::statement("UPDATE log_scripts SET estado = 'error' WHERE estado = 'interrumpido'");
        DB::statement("ALTER TABLE log_scripts MODIFY COLUMN estado ENUM('iniciado','completado','error') NOT NULL DEFAULT 'iniciado'");
    }
};
