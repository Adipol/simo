<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('revisiones_remocion_autoridades', function (Blueprint $table): void {
            $table->dropUnique(['fuente_id', 'fingerprint']);
            $table->unsignedBigInteger('lifecycle_key')->default(0)->after('fingerprint');
            $table->unique(['fuente_id', 'fingerprint', 'lifecycle_key'], 'revision_remocion_fingerprint_lifecycle_unique');
        });
    }

    public function down(): void
    {
        Schema::table('revisiones_remocion_autoridades', function (Blueprint $table): void {
            $table->dropUnique('revision_remocion_fingerprint_lifecycle_unique');
            $table->dropColumn('lifecycle_key');
            $table->unique(['fuente_id', 'fingerprint']);
        });
    }
};
