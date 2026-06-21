<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Runner command
    |--------------------------------------------------------------------------
    | Command used to start the Python runner process. Shown as informational
    | text in the Configuracion de Scripts UI.
    */
    'runner_command' => env('SCRIPTS_RUNNER_COMMAND', 'python runner.py'),

    /*
    |--------------------------------------------------------------------------
    | Scraper timeout fallback (minutes → seconds)
    |--------------------------------------------------------------------------
    | When config_scripts has no scraper row yet, the JS progress bar in
    | Estado falls back to this many minutes to compute the timeout ceiling.
    | Default: 30 min (1800 s).
    */
    'scraper_timeout_fallback_minutes' => (int) env('SCRIPTS_SCRAPER_TIMEOUT_FALLBACK', 30),
];
