<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clasificaciones_feedback', function (Blueprint $table) {
            $table->string('corregido_nombre_normalizado', 300)->nullable()->index()->after('corregido_nombre');
        });
    }

    public function down(): void
    {
        Schema::table('clasificaciones_feedback', function (Blueprint $table) {
            $table->dropIndex(['corregido_nombre_normalizado']);
            $table->dropColumn('corregido_nombre_normalizado');
        });
    }
};
