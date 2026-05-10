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
        Schema::table('cambios', function (Blueprint $table): void {
            $table->timestamp('revisado_at')->nullable()->after('revisado');
            $table->index('revisado_at');
        });

        // Backfill: for cambios already marked revisado=true, use `fecha` as approximation.
        // This is the best available information for when the review occurred.
        DB::table('cambios')
            ->where('revisado', true)
            ->whereNull('revisado_at')
            ->update(['revisado_at' => DB::raw('fecha')]);
    }

    public function down(): void
    {
        Schema::table('cambios', function (Blueprint $table): void {
            $table->dropIndex(['revisado_at']);
            $table->dropColumn('revisado_at');
        });
    }
};
