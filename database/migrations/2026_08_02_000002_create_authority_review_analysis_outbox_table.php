<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('authority_review_analysis_outbox', function (Blueprint $table): void {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->foreignId('authority_removal_review_id')->unique()->constrained('revisiones_remocion_autoridades')->cascadeOnDelete();
            $table->foreignId('cambio_id')->unique()->constrained('cambios')->cascadeOnDelete();
            $table->uuid('dispatch_claim_token')->nullable();
            $table->timestamp('dispatch_claimed_at')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->uuid('processing_claim_token')->nullable();
            $table->timestamp('processing_claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['processed_at', 'dispatched_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_review_analysis_outbox');
    }
};
