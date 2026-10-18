<?php

declare(strict_types=1);

use App\Enums\CambioFeedStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cambios', function (Blueprint $table): void {
            $table->string('feed_status', 32)
                ->default(CambioFeedStatus::Review->value)
                ->after('autoridades_eventos_json')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('cambios', function (Blueprint $table): void {
            $table->dropIndex(['feed_status']);
            $table->dropColumn('feed_status');
        });
    }
};
