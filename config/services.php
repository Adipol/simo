<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'enabled' => env('GEMINI_ENABLED', true),
        'flash_model' => env('GEMINI_FLASH_MODEL', 'gemini-1.5-flash'),
        'pro_model' => env('GEMINI_PRO_MODEL', 'gemini-1.5-pro'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 90),
        'flash_delay' => (int) env('GEMINI_FLASH_DELAY', 4),
        'pro_delay' => (int) env('GEMINI_PRO_DELAY', 30),
    ],

];
