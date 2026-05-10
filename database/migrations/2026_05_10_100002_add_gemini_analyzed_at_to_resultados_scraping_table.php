<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table): void {
            $table->timestamp('gemini_analyzed_at')->nullable()->after('gemini_analyzed');
            $table->index('gemini_analyzed_at');
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table): void {
            $table->dropIndex(['gemini_analyzed_at']);
            $table->dropColumn('gemini_analyzed_at');
        });
    }
};
