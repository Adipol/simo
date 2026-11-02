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
            // Future eligibility and unsent filters select by status; NULL preserves legacy records.
            $table->string('deepseek_title_status', 24)->nullable()->index();
            $table->string('deepseek_title_reason', 160)->nullable();
            $table->string('deepseek_title_evidence', 180)->nullable();
            $table->string('deepseek_title_error_code', 64)->nullable();
            $table->timestamp('deepseek_title_attempted_at')->nullable();
            $table->string('deepseek_title_model', 128)->nullable();
            $table->string('deepseek_title_prompt_version', 64)->nullable();
            $table->timestamp('deepseek_bypass_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('resultados_scraping', function (Blueprint $table): void {
            $table->dropIndex(['deepseek_title_status']);
            $table->dropColumn([
                'deepseek_title_status', 'deepseek_title_reason', 'deepseek_title_evidence',
                'deepseek_title_error_code', 'deepseek_title_attempted_at', 'deepseek_title_model',
                'deepseek_title_prompt_version', 'deepseek_bypass_at',
            ]);
        });
    }
};
