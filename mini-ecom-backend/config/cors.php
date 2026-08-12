<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Laravel ships no `config/cors.php` by default, and the framework's built-in
| defaults answer `Access-Control-Allow-Origin: *` for `api/*`. For a token
| API that is not immediately exploitable — a wildcard origin cannot be
| combined with credentials, and the access token travels in an
| `Authorization` header rather than a cookie — but it does let any page on
| the internet read this API's responses from a victim's browser, and it
| means the moment anyone enables cookie auth the wildcard becomes a live
| cross-site read.
|
| The allowlist below is therefore explicit and env-driven. There is no
| wildcard fallback: an unlisted origin simply receives no CORS headers and
| the browser refuses the response.
|
*/

$origins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string) env('API_CORS_ALLOWED_ORIGINS', '')),
)));

return [

    'paths' => ['v1/*', 'up'],

    'allowed_methods' => ['GET', 'POST', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => $origins,

    'allowed_origins_patterns' => [],

    // Only what the client actually sends. `Idempotency-Key` and `If-Match` are
    // listed because the checkout and cart contracts use them.
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'If-Match',
        'Idempotency-Key',
        'X-Requested-With',
    ],

    // Rate-limit headers are useless to a browser client it cannot read.
    'exposed_headers' => [
        'Retry-After',
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ],

    'max_age' => 3600,

    // Bearer tokens, not cookies. Leaving this false keeps the API out of
    // reach of a cross-site request that rides a logged-in session.
    'supports_credentials' => false,

];
