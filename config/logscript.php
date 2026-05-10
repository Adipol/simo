<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | LogScript Retention Policy
    |--------------------------------------------------------------------------
    |
    | Períodos de retención para los registros de LogScript.
    | Cada estado tiene su propio período antes de ser purgado automáticamente.
    |
    */

    /** Días antes de purgar registros 'interrumpido'. */
    'retention_interrumpido_days' => env('LOGSCRIPT_RETENTION_INTERRUMPIDO_DAYS', 7),

    /** Horas antes de purgar registros 'completado' con 0 resultados. */
    'retention_completado_vacio_hours' => env('LOGSCRIPT_RETENTION_COMPLETADO_VACIO_HOURS', 24),

    /** Días antes de purgar registros 'error'. */
    'retention_error_days' => env('LOGSCRIPT_RETENTION_ERROR_DAYS', 30),

    /** Días antes de purgar registros 'completado' con resultados > 0. */
    'retention_completado_dias' => env('LOGSCRIPT_RETENTION_COMPLETADO_DIAS', 90),
];
