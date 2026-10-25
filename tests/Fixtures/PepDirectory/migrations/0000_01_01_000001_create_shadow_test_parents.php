<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real users/fuentes both use $table->id(); no legacy migrations or records.
        foreach (['users', 'fuentes'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->string('label');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fuentes');
        Schema::dropIfExists('users');
    }
};
