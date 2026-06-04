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

    'dedupe' => [
        'enabled' => env('DEDUPE_ENABLED', true),
    ],

    'gemini' => [
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/models/'),
        'api_key' => env('GEMINI_API_KEY'),
        'enabled' => env('GEMINI_ENABLED', true),
        'flash_model' => env('GEMINI_FLASH_MODEL', 'gemini-1.5-flash'),
        'pro_model' => env('GEMINI_PRO_MODEL', 'gemini-1.5-pro'),
        'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-2.5-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 45),
        'multimodal_timeout' => (int) env('GEMINI_MULTIMODAL_TIMEOUT', 60),
        'flash_delay' => (int) env('GEMINI_FLASH_DELAY', 4),
        'pro_delay' => (int) env('GEMINI_PRO_DELAY', 30),
        'min_confianza_pep' => (int) env('GEMINI_MIN_CONFIANZA_PEP', 70),
        'multimodal_enabled' => (bool) env('GEMINI_MULTIMODAL_ENABLED', true),
        'multimodal_max_payload_bytes' => (int) env('GEMINI_MULTIMODAL_MAX_PAYLOAD_BYTES', 100 * 1024 * 1024),
        'multimodal_max_image_bytes' => (int) env('GEMINI_MULTIMODAL_MAX_IMAGE_BYTES', 5 * 1024 * 1024),
        'negative_examples_enabled' => env('GEMINI_NEGATIVE_EXAMPLES_ENABLED', true),
        'negative_examples_limit' => (int) env('GEMINI_NEGATIVE_EXAMPLES_LIMIT', 5),
    ],

];
