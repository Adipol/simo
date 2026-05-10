<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gemini_usage_log', function (Blueprint $table): void {
            $table->id();
            $table->string('model');
            $table->integer('prompt_tokens')->nullable();
            $table->integer('completion_tokens')->nullable();
            $table->integer('total_tokens')->nullable();
            $table->string('request_type');
            $table->foreignId('cambio_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resultado_scraping_id')->nullable()->constrained('resultados_scraping')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['model', 'created_at']);
            $table->index('request_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gemini_usage_log');
    }
};
