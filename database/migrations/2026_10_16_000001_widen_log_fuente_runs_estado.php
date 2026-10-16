<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('log_fuente_runs', function (Blueprint $table): void {
            $table->string('estado', 64)->change();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            if (DB::getDriverName() === 'pgsql') {
                // DDL lock prevents a new long state between validation and narrowing.
                DB::statement('LOCK TABLE log_fuente_runs IN ACCESS EXCLUSIVE MODE');
            }
            $hasLongState = DB::table('log_fuente_runs')->select(['id', 'estado'])->lazyById()
                ->contains(static fn (object $run): bool => mb_strlen($run->estado) > 20);
            if ($hasLongState) {
                throw new LogicException('Cannot narrow log_fuente_runs.estado while recorded states exceed 20 characters.');
            }

            Schema::table('log_fuente_runs', function (Blueprint $table): void {
                $table->string('estado', 20)->change();
            });
        });
    }
};
