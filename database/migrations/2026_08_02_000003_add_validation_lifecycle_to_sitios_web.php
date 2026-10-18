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
        Schema::table('sitios_web', function (Blueprint $table): void {
            $table->string('validation_status', 20)->default('pending')->after('activo')->index();
            $table->boolean('activation_requested')->default(false)->after('validation_status');
            $table->uuid('validation_token')->nullable()->after('activation_requested');
            $table->timestamp('validation_requested_at')->nullable()->after('validation_token');
            $table->timestamp('validation_started_at')->nullable()->after('validation_requested_at');
            $table->timestamp('validated_at')->nullable()->after('validation_started_at');
            $table->text('validation_diagnostic')->nullable()->after('validated_at');
            $table->string('validation_resolved_url', 500)->nullable()->after('validation_diagnostic');
        });

        DB::table('sitios_web')->where('activo', true)->update([
            'validation_status' => 'valid',
            'activation_requested' => true,
            'validated_at' => now(),
            'validation_diagnostic' => 'Sitio activo previo a la incorporación del ciclo de validación.',
        ]);
    }

    public function down(): void
    {
        Schema::table('sitios_web', function (Blueprint $table): void {
            $table->dropIndex(['validation_status']);
            $table->dropColumn([
                'validation_status', 'activation_requested', 'validation_token',
                'validation_requested_at', 'validation_started_at', 'validated_at',
                'validation_diagnostic', 'validation_resolved_url',
            ]);
        });
    }
};
