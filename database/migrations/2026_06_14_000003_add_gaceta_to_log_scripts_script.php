<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Widens the log_scripts.script constraint to include 'gaceta'.
 *
 * The original CREATE TABLE restricted script to ('scraper','pep_monitor').
 * The gaceta_collector runner writes log_scripts rows with script='gaceta'
 * (both a wrapper row from runner.py and a counts row from main.py).
 * Without this migration those INSERTs violate the CHECK constraint.
 *
 * Pattern mirrors 2026_03_03_202103_add_interrumpido_to_log_scripts_estado.php:
 *   - PostgreSQL: DROP + ADD CHECK constraint
 *   - SQLite:     recreate table with updated CHECK (ALTER on CHECK not supported)
 *   - MySQL:      MODIFY COLUMN to widen ENUM
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE log_scripts DROP CONSTRAINT IF EXISTS log_scripts_script_check');
            DB::statement("ALTER TABLE log_scripts ADD CONSTRAINT log_scripts_script_check CHECK (script IN ('scraper','pep_monitor','gaceta'))");

            return;
        }

        if ($driver === 'sqlite') {
            // SQLite does not support ALTER on CHECK constraints; recreate the table.
            // Preserves the estado constraint added by 2026_03_03_202103.
            DB::statement('PRAGMA foreign_keys = OFF');
            DB::statement("
                CREATE TABLE log_scripts_new (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    script TEXT CHECK(script IN ('scraper','pep_monitor','gaceta')) NOT NULL,
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

        // MySQL / MariaDB
        DB::statement("ALTER TABLE log_scripts MODIFY COLUMN script ENUM('scraper','pep_monitor','gaceta') NOT NULL");
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            // Remove rows with script='gaceta' before narrowing the constraint
            DB::table('log_scripts')->where('script', 'gaceta')->delete();
            DB::statement('ALTER TABLE log_scripts DROP CONSTRAINT IF EXISTS log_scripts_script_check');
            DB::statement("ALTER TABLE log_scripts ADD CONSTRAINT log_scripts_script_check CHECK (script IN ('scraper','pep_monitor'))");

            return;
        }

        if ($driver === 'sqlite') {
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
            DB::statement("DELETE FROM log_scripts WHERE script = 'gaceta'");
            DB::statement('INSERT INTO log_scripts_new SELECT id, script, estado, inicio, fin, duracion_segundos, items_procesados, items_resultado, errores, mensaje_error FROM log_scripts');
            DB::statement('DROP TABLE log_scripts');
            DB::statement('ALTER TABLE log_scripts_new RENAME TO log_scripts');
            DB::statement('CREATE INDEX log_scripts_script_index ON log_scripts (script)');
            DB::statement('CREATE INDEX log_scripts_inicio_index ON log_scripts (inicio)');
            DB::statement('CREATE INDEX log_scripts_estado_index ON log_scripts (estado)');
            DB::statement('PRAGMA foreign_keys = ON');

            return;
        }

        // MySQL / MariaDB
        DB::table('log_scripts')->where('script', 'gaceta')->delete();
        DB::statement("ALTER TABLE log_scripts MODIFY COLUMN script ENUM('scraper','pep_monitor') NOT NULL");
    }
};
