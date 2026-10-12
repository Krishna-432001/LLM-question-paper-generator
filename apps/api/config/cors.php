<?php

declare(strict_types=1);

return [

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    /*
    | The reviewer console runs on a separate origin from the API, so the
    | browser needs these explicitly. A wildcard would also allow any site a
    | reviewer visits to call the API with their credentials, so the origins
    | are listed rather than opened.
    */
    'allowed_origins' => array_filter(explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://127.0.0.1:3000'
    ))),

    // In local development the console is often opened from another device on
    // the LAN, and DHCP moves the IP. Private-range origins on :3000 are
    // allowed there only; production lists its origins explicitly.
    'allowed_origins_patterns' => env('APP_ENV') === 'local'
        ? ['#^http://(10(\.\d{1,3}){3}|192\.168(\.\d{1,3}){2}|172\.(1[6-9]|2\d|3[01])(\.\d{1,3}){2}):3000$#']
        : [],

    'allowed_headers' => ['*'],

    // Lets the client read the tenant it was resolved into.
    'exposed_headers' => ['X-Organization-Id'],

    'max_age' => 3600,

    // Bearer tokens, not cookies — so credentials are not required.
    'supports_credentials' => false,

];
