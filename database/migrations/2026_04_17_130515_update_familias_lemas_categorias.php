<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('familias_lemas')
            ->where('categoria', 'designacion')
            ->update(['categoria' => 'PEP-designacion']);

        DB::table('familias_lemas')
            ->where('categoria', 'renuncia')
            ->update(['categoria' => 'PEP-renuncia']);

        DB::table('familias_lemas')
            ->where('categoria', 'crimen')
            ->update(['categoria' => 'OPI-crimen']);
    }

    public function down(): void
    {
        DB::table('familias_lemas')
            ->where('categoria', 'PEP-designacion')
            ->update(['categoria' => 'designacion']);

        DB::table('familias_lemas')
            ->where('categoria', 'PEP-renuncia')
            ->update(['categoria' => 'renuncia']);

        DB::table('familias_lemas')
            ->where('categoria', 'OPI-crimen')
            ->update(['categoria' => 'crimen']);
    }
};
