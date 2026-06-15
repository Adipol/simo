<?php

/**
 * Gaceta Oficial collector configuration.
 *
 * Per-country settings for collecting decrees from official gazette sites.
 * All environment variable reads are wrapped here — never call env() outside config/.
 *
 * PDF download is disabled in Slice 1 (pdf.enabled = false).
 * The gaceta_pdf filesystem disk is a seam — configured in config/filesystems.php
 * but never written to until a future slice enables it.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Country Sources
    |--------------------------------------------------------------------------
    |
    | Per-country gazette configuration. Keyed by ISO 3166-1 alpha-2 code.
    | The Python collector selects its config block by the GACETA_PAIS env var.
    |
    */

    'countries' => [

        'BO' => [
            'base_url'      => env('GACETA_BO_BASE_URL', 'https://gacetaoficialdebolivia.gob.bo'),
            'listado_path'  => '/normas/listadonor/11',
            'user_agent'    => env('GACETA_USER_AGENT', 'SIMO-Collector/1.0 (+https://github.com/simo)'),
            'max_pages'     => (int) env('GACETA_BO_MAX_PAGES', 5),
            'throttle'      => [
                'delay_min_ms' => (int) env('GACETA_DELAY_MIN_MS', 800),
                'delay_max_ms' => (int) env('GACETA_DELAY_MAX_MS', 2000),
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | PDF Handling
    |--------------------------------------------------------------------------
    |
    | PDF download is OFF in Slice 1. Only the URL is stored (pdf_url column).
    | Enable in a future slice when archival storage is provisioned.
    |
    */

    'pdf' => [
        'enabled' => (bool) env('GACETA_PDF_DOWNLOAD', false),
        'disk'    => env('GACETA_PDF_DISK', 'gaceta_pdf'),
    ],

];
