<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Problem type base URI
    |--------------------------------------------------------------------------
    |
    | RFC 9457 problem details identify a failure class by a stable URI. Clients branch on
    | `type` and never on `title` or `detail` — those are human-readable, get localised, and
    | get reworded by whoever is tidying copy that sprint.
    |
    */

    'problem_base_uri' => env('API_PROBLEM_BASE_URI', 'https://api.grocerly.example/problems'),

    /*
    |--------------------------------------------------------------------------
    | Token lifetimes
    |--------------------------------------------------------------------------
    |
    | A short-lived access token limits the damage of a leak; the refresh token is the
    | long-lived credential and is single-use, so a stolen one is detectable on replay.
    |
    */

    'access_token_ttl' => (int) env('API_ACCESS_TOKEN_TTL', 15 * 60),

    'refresh_token_ttl' => (int) env('API_REFRESH_TOKEN_TTL', 30 * 24 * 60 * 60),

    // A verification code is destroyed after this many incorrect attempts. The durable counter
    // is intentionally independent of any IP-based throttle, because attackers can rotate IPs.
    'verification_max_attempts' => (int) env('API_VERIFICATION_MAX_ATTEMPTS', 5),

    // Seconds to cache public catalogue payloads. 0 is a deliberate opt-out for debugging or
    // deployments where the cache store is not shared between application instances.
    'catalog_cache_ttl' => (int) env('API_CATALOG_CACHE_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Registration is throttled primarily as an account-enumeration control: the endpoint
    | returns 409 for an already-registered email, which is a disclosure. Throttling plus
    | constant-time comparison keeps bulk enumeration impractical.
    |
    */

    'rate_limits' => [
        // Registration, password-reset and resend routes: one IP should not turn the endpoint
        // into an account-enumeration or mail-relay service.
        'auth' => (int) env('API_RATE_LIMIT_AUTH', 5),

        // Login applies both the IP limit above and this per-email limit. The latter protects a
        // targeted account from password spraying spread across a botnet of IP addresses.
        'login_email' => (int) env('API_RATE_LIMIT_LOGIN_EMAIL', 10),

        // Refreshes are unauthenticated because their access token may have expired; without a
        // dedicated limit this was the one credential endpoint that could be hit without bound.
        'refresh' => (int) env('API_RATE_LIMIT_REFRESH', 10),

        // Each 6-digit code also has a durable failed-attempt budget in the DB. This is a
        // cheap edge control for obvious bursts before any DB write occurs.
        'verification' => (int) env('API_RATE_LIMIT_VERIFICATION', 5),

        // Telegram does not use Bearer auth, so abuse protection must live at the webhook edge.
        'webhook' => (int) env('API_RATE_LIMIT_WEBHOOK', 60),

        'authenticated' => (int) env('API_RATE_LIMIT_AUTHENTICATED', 120),
        'catalog' => (int) env('API_RATE_LIMIT_CATALOG', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend URL
    |--------------------------------------------------------------------------
    |
    | This API has no Blade views of its own — password-reset and email-verification
    | notifications link out to whatever client actually renders a page for the
    | customer to act on. There is no frontend in this repository yet, so this
    | defaults to a placeholder; a real client sets API_FRONTEND_URL and reads the
    | token/id/hash back out of the query string once the user lands there.
    |
    */

    'frontend_url' => env('API_FRONTEND_URL', 'http://localhost:3000'),

];
