<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the gaceta_normas table.
 *
 * Stores legal decrees collected from official gazette sites.
 * Country-agnostic via pais column; dedup via UNIQUE(pais, gaceta_id_externo)
 * and UNIQUE(pais, numero_decreto).
 *
 * PDF archival and texto_completo are seams for future slices — not populated in Slice 1.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gaceta_normas', function (Blueprint $table): void {
            $table->id();
            $table->char('pais', 2)->default('BO')->comment('ISO 3166-1 alpha-2 country code');
            $table->unsignedBigInteger('gaceta_id_externo')->comment('Internal ID from the gazette site — used as cursor for incremental collection');
            $table->string('numero_decreto', 30)->nullable()->comment('Official decree number e.g. 0001/2026');
            $table->string('tipo_norma', 40)->comment('e.g. Decreto Presidencial');
            $table->string('edicion', 20)->nullable()->comment('Gazette edition number');
            $table->date('fecha_publicacion')->nullable()->comment('Official publication date');
            $table->text('sumario')->comment('Full summary text from the listing — source for regex extraction');
            $table->text('texto_completo')->nullable()->comment('Seam: full decree text for Slice 4 detail extraction');
            $table->string('pdf_url', 255)->nullable()->comment('URL to the PDF on the gazette site — URL-only, no download in Slice 1');
            $table->string('pdf_archivado_path', 255)->nullable()->comment('Seam: local archive path for future PDF archival');
            $table->jsonb('raw_json')->nullable()->comment('Raw JSON payload from the source API/HTML parser (jsonb for future GIN indexing / SISA dedup querying)');
            $table->string('estado_extraccion', 20)->default('pendiente')
                ->comment('pendiente | completado | requiere_detalle | error');
            $table->timestamps();

            // Primary dedup key: one row per gazette entry per country
            $table->unique(['pais', 'gaceta_id_externo'], 'gaceta_normas_pais_id_externo_unique');

            // Secondary dedup: same decree number cannot appear twice for the same country
            $table->unique(['pais', 'numero_decreto'], 'gaceta_normas_pais_numero_decreto_unique');

            // Filter indexes (pais is covered by the pais-leading UNIQUE above + the FK index)
            $table->index('fecha_publicacion');
            $table->index('estado_extraccion');
        });

        Schema::table('gaceta_normas', function (Blueprint $table): void {
            $table->foreign('pais')->references('codigo')->on('paises');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gaceta_normas');
    }
};
