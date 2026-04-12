<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\FamiliaLema;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class KeywordsDesdeRaicesSeeder extends Seeder
{
    /**
     * Crea una keyword en palabras_clave por cada raíz activa de familias_lemas.
     * Idempotente: no duplica si la keyword ya existe.
     */
    public function run(): void
    {
        $mapping = [
            'designacion' => 'PEP',
            'renuncia' => 'PEP',
            'crimen' => 'OPI',
        ];

        $familias = FamiliaLema::active()->get();
        $inserted = 0;

        foreach ($familias as $familia) {
            $categoria = $mapping[$familia->categoria->value] ?? 'PEP';

            $exists = DB::table('palabras_clave')
                ->where('keyword', $familia->raiz)
                ->exists();

            if (! $exists) {
                DB::table('palabras_clave')->insert([
                    'keyword' => $familia->raiz,
                    'categoria' => $categoria,
                    'activo' => true,
                    'fecha_creacion' => now(),
                ]);
                $inserted++;
            }
        }

        $this->command?->info("Keywords creadas: {$inserted} (de {$familias->count()} familias activas)");
    }
}
