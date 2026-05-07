<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Cambio;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupImagenesCambios extends Command
{
    protected $signature = 'cleanup:imagenes-cambios
                            {--days=90 : Días a mantener antes de borrar}';

    protected $description = 'Borra archivos de imagen en img_cambios/ cuyo cambio no existe o es más viejo que --days días';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        $umbral = Carbon::now()->subDays($days);

        // Disk dedicado en config/filesystems.php apuntando a storage/app/img_cambios
        $disk = Storage::disk('img_cambios');

        // Si el root del disk no existe físicamente, terminar sin error
        if (! is_dir($disk->path(''))) {
            $this->info('Directorio img_cambios/ no existe. Nada que limpiar.');

            return self::SUCCESS;
        }

        $archivos = $disk->files('');

        if (empty($archivos)) {
            $this->info('Directorio img_cambios/ vacío. Nada que limpiar.');

            return self::SUCCESS;
        }

        $borrados = 0;
        $bytesLiberados = 0;

        foreach ($archivos as $archivo) {
            $nombre = basename($archivo);
            $cambioId = $this->parseCambioId($nombre);

            if ($cambioId === null) {
                // Nombre no coincide con el patrón esperado; ignorar
                continue;
            }

            $debeEliminar = $this->debeEliminarArchivo($cambioId, $umbral);

            if ($debeEliminar) {
                $bytesLiberados += $disk->size($archivo);
                $disk->delete($archivo);
                $borrados++;
            }
        }

        $kb = round($bytesLiberados / 1024, 2);
        $this->info("Limpieza completada: {$borrados} archivo(s) borrado(s), {$kb} KB liberados.");

        Log::channel('gemini')->info('cleanup:imagenes-cambios completado', [
            'borrados'        => $borrados,
            'bytes_liberados' => $bytesLiberados,
            'days'            => $days,
        ]);

        return self::SUCCESS;
    }

    /**
     * Extrae el cambio_id del nombre de archivo con formato {cambio_id}_{idx}.{ext}.
     *
     * @return int|null  null si el nombre no sigue el patrón esperado
     */
    private function parseCambioId(string $nombre): ?int
    {
        // Patrón: {entero}_{entero}.{ext}  → capturar el primer entero
        if (preg_match('/^(\d+)_\d+\.\w+$/', $nombre, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Determina si el archivo debe eliminarse.
     *
     * Eliminar cuando:
     * - El Cambio no existe en BD, O
     * - El Cambio existe pero su `fecha` es anterior al umbral
     */
    private function debeEliminarArchivo(int $cambioId, Carbon $umbral): bool
    {
        $cambio = Cambio::find($cambioId);

        if ($cambio === null) {
            return true;
        }

        // $cambio->fecha es un datetime; comparar con umbral
        return $cambio->fecha !== null && $cambio->fecha->lt($umbral);
    }
}
