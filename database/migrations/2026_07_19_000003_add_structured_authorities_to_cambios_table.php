<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cambios', function (Blueprint $table): void {
            $table->json('autoridades_eventos_json')->nullable()->after('diff_texto');
        });
    }

    public function down(): void
    {
        Schema::table('cambios', function (Blueprint $table): void {
            $table->dropColumn('autoridades_eventos_json');
        });
    }
};
