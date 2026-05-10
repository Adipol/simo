<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini Daily Token Limit
    |--------------------------------------------------------------------------
    |
    | Maximum number of tokens allowed per day for budget alerting on the
    | dashboard. Set to null to hide the limit indicator (shows N/A).
    |
    */
    'daily_token_limit' => env('GEMINI_DAILY_TOKEN_LIMIT') !== null
        ? (int) env('GEMINI_DAILY_TOKEN_LIMIT')
        : null,

];
