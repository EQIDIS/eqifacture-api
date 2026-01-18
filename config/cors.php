<?php

return [
    /*
    |--------------------------------------------------------------------------
    | CORS Configuration
    |--------------------------------------------------------------------------
    */

    // Allowed origins (use '*' for all, or specific domains)
    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),

    // Allowed methods
    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    // Allowed headers
    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With'],

    // Max age for preflight cache (24 hours)
    'max_age' => 86400,
];
