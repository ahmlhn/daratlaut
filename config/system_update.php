<?php

return [
    // Disabled by default. Enable on production via SYSTEM_UPDATE_ENABLED=true.
    'enabled' => (bool) env('SYSTEM_UPDATE_ENABLED', false),

    // Comma-separated roles allowed to run updates.
    'allow_roles' => array_values(array_filter(array_map('trim', explode(',', (string) env('SYSTEM_UPDATE_ALLOW_ROLES', 'owner,admin'))))),

    // Upload limit (soft limit; server php.ini upload limits still apply).
    'max_package_mb' => (int) env('SYSTEM_UPDATE_MAX_MB', 300),

    // Number of files copied per "step" request.
    'chunk_size' => (int) env('SYSTEM_UPDATE_CHUNK_SIZE', 200),

    // Optional: allow downloading a package ZIP from a URL (must be publicly accessible).
    'allow_download' => (bool) env('SYSTEM_UPDATE_ALLOW_DOWNLOAD', false),
    'package_url' => env('SYSTEM_UPDATE_PACKAGE_URL'),

    // GitHub (private repo support).
    // Used for "check latest on main" + download zipball from GitHub API.
    'github' => [
        'enabled' => (bool) env('SYSTEM_UPDATE_GITHUB_ENABLED', true),
        'owner' => env('SYSTEM_UPDATE_GITHUB_OWNER'),
        'repo' => env('SYSTEM_UPDATE_GITHUB_REPO'),
        'branch' => env('SYSTEM_UPDATE_GITHUB_BRANCH', 'main'),
        // Optional fallback: can also be stored via admin UI (encrypted file under storage/).
        'token' => env('SYSTEM_UPDATE_GITHUB_TOKEN'),
        'api_base' => env('SYSTEM_UPDATE_GITHUB_API_BASE', 'https://api.github.com'),
        'api_version' => env('SYSTEM_UPDATE_GITHUB_API_VERSION', '2022-11-28'),

        // Release artifact built by GitHub Actions (recommended: includes vendor + public/build).
        'release_tag' => env('SYSTEM_UPDATE_GITHUB_RELEASE_TAG', 'panel-main-latest'),
        'release_asset' => env('SYSTEM_UPDATE_GITHUB_RELEASE_ASSET', 'update-package.zip'),
    ],

    // Paths that should never be overwritten by an update package.
    // Use forward slashes, relative to project root.
    'exclude_paths' => [
        '.env',
        '.env.example',
        'storage/',
        'bootstrap/cache/',
        'public/storage/',
        'public/uploads/',
    ],
];
