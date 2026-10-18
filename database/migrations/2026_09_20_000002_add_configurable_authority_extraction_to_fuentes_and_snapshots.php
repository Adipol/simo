<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuentes', function (Blueprint $table): void {
            $table->json('authority_extraction_config')->nullable();
            $table->uuid('authority_extraction_revision')->nullable();
            $table->uuid('authority_extraction_confirmed_revision')->nullable();
        });

        Schema::table('snapshots', function (Blueprint $table): void {
            $table->string('authority_extraction_config_fingerprint', 64)->nullable();
            $table->uuid('authority_extraction_revision')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('snapshots', function (Blueprint $table): void {
            $table->dropColumn([
                'authority_extraction_config_fingerprint',
                'authority_extraction_revision',
            ]);
        });

        Schema::table('fuentes', function (Blueprint $table): void {
            $table->dropColumn([
                'authority_extraction_config',
                'authority_extraction_revision',
                'authority_extraction_confirmed_revision',
            ]);
        });
    }
};
