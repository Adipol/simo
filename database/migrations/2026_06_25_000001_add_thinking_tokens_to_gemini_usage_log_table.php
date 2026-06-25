<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gemini_usage_log', function (Blueprint $table): void {
            $table->integer('thinking_tokens')->nullable()->after('completion_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('gemini_usage_log', function (Blueprint $table): void {
            $table->dropColumn('thinking_tokens');
        });
    }
};
