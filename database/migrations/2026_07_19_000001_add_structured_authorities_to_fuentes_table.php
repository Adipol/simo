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
        Schema::table('fuentes', function (Blueprint $table): void {
            $table->string('autoridades_extractor', 50)
                ->nullable()
                ->after('selector_css')
                ->comment('Adaptador estructurado de autoridades; null conserva extracción de texto');
        });

        DB::table('fuentes')
            ->where('url', 'https://www.asuss.gob.bo/recursos-humanos/#autoridades')
            ->update(['autoridades_extractor' => 'divi_blurb']);
    }

    public function down(): void
    {
        Schema::table('fuentes', function (Blueprint $table): void {
            $table->dropColumn('autoridades_extractor');
        });
    }
};
