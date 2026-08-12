<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bakong KHQR payment configuration
    |--------------------------------------------------------------------------
    |
    | Every value comes from the deployment environment. Never place a Bakong
    | token or merchant identity in source control. `enabled` is deliberately
    | false by default, preventing an incomplete configuration from exposing a
    | payment option to customers.
    |
    */
    'enabled' => filter_var(env('BAKONG_ENABLED', false), FILTER_VALIDATE_BOOL),

    'base_url' => rtrim((string) env('BAKONG_BASE_URL', 'https://api-bakong.nbc.gov.kh'), '/'),

    'check_transaction_path' => (string) env('BAKONG_CHECK_TRANSACTION_PATH', '/v1/check_transaction_by_md5'),

    'api_token' => env('BAKONG_API_TOKEN'),

    // `individual` uses KHQR tag 29. `merchant` uses tag 30 and requires both
    // a merchant ID and acquiring-bank label issued for the merchant profile.
    'profile_type' => (string) env('BAKONG_PROFILE_TYPE', 'individual'),
    'account_id' => env('BAKONG_ACCOUNT_ID'),
    'merchant_name' => env('BAKONG_MERCHANT_NAME'),
    'merchant_city' => env('BAKONG_MERCHANT_CITY', 'Phnom Penh'),
    'merchant_id' => env('BAKONG_MERCHANT_ID'),
    'acquiring_bank' => env('BAKONG_ACQUIRING_BANK'),
    'mobile_number' => env('BAKONG_MOBILE_NUMBER'),

    // `USD` is represented by ISO-4217 numeric code 840 in KHQR; KHR uses 116.
    'currency' => (string) env('BAKONG_CURRENCY', 'USD'),

    // A short server-side payment window limits how long a dynamic QR reference
    // remains usable. It is intentionally no greater than the order reservation.
    'payment_ttl_minutes' => (int) env('BAKONG_PAYMENT_TTL_MINUTES', 20),

    // Network timeouts prevent provider unavailability from exhausting PHP workers.
    'connect_timeout_seconds' => (int) env('BAKONG_CONNECT_TIMEOUT_SECONDS', 5),
    'timeout_seconds' => (int) env('BAKONG_TIMEOUT_SECONDS', 10),
];
