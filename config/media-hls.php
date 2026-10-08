<?php

return [

    /*
     * Public tus endpoint returned to the browser. Bytes never pass through
     * the Next.js BFF. Override when APP_URL is not the public API host.
     */
    'endpoint' => env('TUS_PUBLIC_ENDPOINT'),

    /*
     * Shared secret tusd sends on hook requests (query string, localhost only).
     * Tests and operators may also send it as X-Tus-Hook-Secret.
     */
    'hook_secret' => env('TUS_HOOK_SECRET'),

    'upload_dir' => env('TUS_UPLOAD_DIR', storage_path('app/tus')),

    'max_bytes' => (int) env('VIDEO_MAX_UPLOAD_BYTES', 8 * 1024 * 1024 * 1024),

    'token_ttl_seconds' => (int) env('VIDEO_UPLOAD_TOKEN_TTL', 6 * 60 * 60),

    'abandon_after_hours' => (int) env('VIDEO_ABANDON_AFTER_HOURS', 24),

];
