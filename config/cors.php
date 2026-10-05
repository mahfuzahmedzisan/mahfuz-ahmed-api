<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    /*
     * This API is only ever called server-to-server by the Next.js BFF
     * (axios on the Node side), never directly from a browser - the
     * browser only ever talks to the Next.js app, which holds the
     * encrypted session cookie and attaches Bearer tokens itself. CORS is
     * therefore defense-in-depth, not the primary access control: it is
     * locked to FRONTEND_URLS (FRONTEND_URL fallback) so that even a
     * misconfigured or compromised client-side script cannot call this API
     * directly from the browser. The real caller gate is EnsureTrustedBff.
     */
    'paths' => ['api/*', 'oauth/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter(array_map(
        static fn (string $origin): string => rtrim(trim($origin), '/'),
        explode(',', (string) env('FRONTEND_URLS', env('FRONTEND_URL', '')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'X-Requested-With', 'X-BFF-Secret', 'X-Frontend-Origin'],

    'exposed_headers' => [],

    'max_age' => 0,

    // No cookies are ever sent cross-origin to this API; auth is Bearer-token only.
    'supports_credentials' => false,

];
