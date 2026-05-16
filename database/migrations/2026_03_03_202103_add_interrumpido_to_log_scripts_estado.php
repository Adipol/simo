<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            // SQLite does not support ALTER on CHECK constraints; recreate the table.
            // The original enum() created: CHECK(estado IN ('iniciado','completado','error')).
            // We need to widen it to include 'interrumpido'.
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement("
                CREATE TABLE log_scripts_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    script TEXT CHECK(script IN ('scraper','pep_monitor')) NOT NULL,
                    estado TEXT CHECK(estado IN ('iniciado','completado','error','interrumpido')) NOT NULL DEFAULT 'iniciado',
                    inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    fin DATETIME,
                    duracion_segundos NUMERIC,
                    items_procesados INTEGER NOT NULL DEFAULT 0,
                    items_resultado INTEGER NOT NULL DEFAULT 0,
                    errores INTEGER NOT NULL DEFAULT 0,
                    mensaje_error TEXT
                )
            ");
            DB::statement('INSERT INTO log_scripts_new SELECT id, script, estado, inicio, fin, duracion_segundos, items_procesados, items_resultado, errores, mensaje_error FROM log_scripts');
            DB::statement('DROP TABLE log_scripts');
            DB::statement('ALTER TABLE log_scripts_new RENAME TO log_scripts');
            DB::statement('CREATE INDEX log_scripts_script_index ON log_scripts (script)');
            DB::statement('CREATE INDEX log_scripts_inicio_index ON log_scripts (inicio)');
            DB::statement('CREATE INDEX log_scripts_estado_index ON log_scripts (estado)');
            DB::statement('PRAGMA foreign_keys = ON');

            return;
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
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement("
                CREATE TABLE log_scripts_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    script TEXT CHECK(script IN ('scraper','pep_monitor')) NOT NULL,
                    estado TEXT CHECK(estado IN ('iniciado','completado','error')) NOT NULL DEFAULT 'iniciado',
                    inicio DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    fin DATETIME,
                    duracion_segundos NUMERIC,
                    items_procesados INTEGER NOT NULL DEFAULT 0,
                    items_resultado INTEGER NOT NULL DEFAULT 0,
                    errores INTEGER NOT NULL DEFAULT 0,
                    mensaje_error TEXT
                )
            ");
            DB::statement("UPDATE log_scripts SET estado = 'error' WHERE estado = 'interrumpido'");
            DB::statement('INSERT INTO log_scripts_new SELECT id, script, estado, inicio, fin, duracion_segundos, items_procesados, items_resultado, errores, mensaje_error FROM log_scripts');
            DB::statement('DROP TABLE log_scripts');
            DB::statement('ALTER TABLE log_scripts_new RENAME TO log_scripts');
            DB::statement('CREATE INDEX log_scripts_script_index ON log_scripts (script)');
            DB::statement('CREATE INDEX log_scripts_inicio_index ON log_scripts (inicio)');
            DB::statement('CREATE INDEX log_scripts_estado_index ON log_scripts (estado)');
            DB::statement('PRAGMA foreign_keys = ON');

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
