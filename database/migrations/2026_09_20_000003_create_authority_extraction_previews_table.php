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
        Schema::create('authority_extraction_previews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('fuente_id')->unique()->constrained('fuentes')->cascadeOnDelete();
            $table->uuid('source_revision');
            $table->string('source_url', 500);
            $table->string('status', 20);
            $table->unsignedInteger('attempts')->default(0);
            $table->uuid('lease_token')->nullable();
            $table->timestamp('lease_expires_at')->nullable();
            $table->char('config_fingerprint', 64)->nullable();
            $table->json('authorities_json')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE authority_extraction_previews ADD CONSTRAINT authority_preview_status_check CHECK (status IN ('pending', 'running', 'ready', 'incomplete', 'error', 'stale'))");
            DB::statement('ALTER TABLE authority_extraction_previews ADD CONSTRAINT authority_preview_attempts_check CHECK (attempts >= 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('authority_extraction_previews');
    }
};
