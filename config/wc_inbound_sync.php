<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WooCommerce → POS inbound sync
    |--------------------------------------------------------------------------
    |
    | Shared secret for requests from the WordPress plugin (Bearer token).
    | Generate a long random string: php -r "echo bin2hex(random_bytes(32));"
    |
    */
    'secret' => env('WC_INBOUND_SYNC_SECRET', ''),

    /*
    | Allowed clock skew for optional HMAC timestamp validation (seconds).
    */
    'max_timestamp_skew_seconds' => (int) env('WC_INBOUND_SYNC_MAX_SKEW', 300),

    /*
    | Require X-WC-Sync-Timestamp + X-WC-Sync-Signature (HMAC-SHA256 of
    | timestamp + "\n" + raw body) in addition to Bearer secret.
    */
    'require_hmac' => (bool) env('WC_INBOUND_SYNC_REQUIRE_HMAC', false),

];
