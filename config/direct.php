<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Direct Public Base URL
    |--------------------------------------------------------------------------
    |
    | Optional base URL used to build public Direct links (Settings -> Link
    | Isolir). When empty, the app will use the current request host and
    | fall back to APP_URL.
    |
    */
    'public_base_url' => env('DIRECT_PUBLIC_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Direct Admin Base URL
    |--------------------------------------------------------------------------
    |
    | Optional base URL used inside WA chat notification links to open
    | /chat/index.php. Tenant-level reminder_base_url will take precedence.
    |
    */
    'admin_base_url' => env('DIRECT_ADMIN_BASE_URL', ''),

    /*
    |--------------------------------------------------------------------------
    | Allowed Origins For Direct API
    |--------------------------------------------------------------------------
    |
    | Comma-separated list of origins or bare domains allowed by CORS for
    | /direct/api.php. Example:
    |   https://isolir.example.com,https://my.example.com,example.net
    |
    */
    'allowed_origins' => env('DIRECT_ALLOWED_ORIGINS', ''),

    /*
    |--------------------------------------------------------------------------
    | Allow All Origins
    |--------------------------------------------------------------------------
    |
    | If true, any Origin can access /direct/api.php (token still required).
    | Keep false for stricter production security.
    |
    */
    'allow_all_origins' => (bool) env('DIRECT_ALLOW_ALL_ORIGINS', false),
];
