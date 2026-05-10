<?php

declare(strict_types=1);

return [
    'summary_cache_ttl' => env('DASHBOARD_SUMMARY_CACHE_TTL', 60),
    'health_cache_ttl' => env('DASHBOARD_HEALTH_CACHE_TTL', 15),
    'backlog_aging_days' => env('DASHBOARD_BACKLOG_AGING_DAYS', 3),
    'discovery_min_confidence' => env('DASHBOARD_DISCOVERY_MIN_CONFIDENCE', 0.8),
    'scraper_warning_threshold_hours' => env('DASHBOARD_SCRAPER_WARNING_HOURS', 6),
    'queue_warning_threshold' => env('DASHBOARD_QUEUE_WARNING_THRESHOLD', 50),
    'hero_formula' => [
        'riesgo_alto_weight' => env('DASHBOARD_HERO_RIESGO_ALTO_W', 3),
        'es_mae_weight' => env('DASHBOARD_HERO_ES_MAE_W', 2),
        'aging_divisor' => env('DASHBOARD_HERO_AGING_DIVISOR', 3),
    ],
];
