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
        'token' => env('POSTMARK_TOKEN'),
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

    /*
    |--------------------------------------------------------------------------
    | Midtrans Payment Gateway
    |--------------------------------------------------------------------------
    |
    | Midtrans credentials for payment processing.
    | Get your keys from https://dashboard.midtrans.com
    |
    */
    'midtrans' => [
        'is_production' => env('MIDTRANS_IS_PRODUCTION', false),
        'server_key' => env('MIDTRANS_SERVER_KEY', ''),
        'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | MikroTik Isolir Integration
    |--------------------------------------------------------------------------
    |
    | MikroTik REST API for automatic suspend/unsuspend.
    | Requires RouterOS v7+ with REST API enabled.
    |
    */
    'mikrotik' => [
        'enabled' => env('MIKROTIK_ENABLED', false),
        'api_url' => env('MIKROTIK_API_URL', 'https://router.example.com'),
        'username' => env('MIKROTIK_USERNAME', 'api'),
        'password' => env('MIKROTIK_PASSWORD', ''),
    ],

];
