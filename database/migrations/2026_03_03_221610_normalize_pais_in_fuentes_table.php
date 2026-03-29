<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Normalizar los valores existentes a código ISO (best-effort)
        DB::table('fuentes')->whereNotNull('pais')->get()->each(function ($f) {
            $codigo = match (strtoupper(trim($f->pais ?? ''))) {
                'BOLIVIA', 'BO'           => 'BO',
                'HONDURAS', 'HN'          => 'HN',
                'PARAGUAY', 'PY'          => 'PY',
                'NICARAGUA', 'NI'         => 'NI',
                'GUATEMALA', 'GT'         => 'GT',
                'EL SALVADOR', 'SV'       => 'SV',
                'PERU', 'PERÚ', 'PE'      => 'PE',
                'ARGENTINA', 'AR'         => 'AR',
                'CHILE', 'CL'             => 'CL',
                'COLOMBIA', 'CO'          => 'CO',
                'MEXICO', 'MÉXICO', 'MX'  => 'MX',
                default                   => null,
            };
            DB::table('fuentes')->where('id', $f->id)->update(['pais' => $codigo]);
        });

        // 2. Cambiar columna a char(2) nullable
        Schema::table('fuentes', function (Blueprint $table) {
            $table->char('pais', 2)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('fuentes', function (Blueprint $table) {
            $table->string('pais', 100)->nullable()->change();
        });
    }
};
