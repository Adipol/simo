<?php

namespace App\Console\Commands;

use App\Models\LogScript;
use Illuminate\Console\Command;

class LimpiarLogs extends Command
{
    protected $signature = 'simo:limpiar-logs';

    protected $description = 'Limpia huerfanos y aplica politica de retencion en log_scripts';

    public function handle(): int
    {
        foreach (['scraper', 'pep_monitor'] as $script) {
            $n = LogScript::limpiarHuerfanos($script);
            if ($n > 0) {
                $this->info("[$script] {$n} registro(s) huerfano(s) marcados como interrumpido.");
            }
        }

        $resultado = LogScript::aplicarRetencion();
        $this->info("Retencion aplicada: {$resultado['total']} registro(s) eliminado(s).");
        $this->table(
            ['Tipo', 'Eliminados'],
            [
                ['Interrumpidos (>7d)',        $resultado['interrumpidos']],
                ['Completados vacios (>24h)',   $resultado['completados_vacios']],
                ['Errores (>30d)',              $resultado['errores_viejos']],
                ['Completados con datos (>90d)', $resultado['completados_viejos']],
            ]
        );

        return self::SUCCESS;
    }
}
