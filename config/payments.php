<?php

return [

    'currency' => 'usd',

    /*
    | Sites allowed to embed the payment page in an iframe (CSP frame-ancestors).
    | Comma separated in .env. Host sources only: a port may be a number or *, and
    | a leading *. matches any subdomain. The app's own origin is always allowed.
    */
    'embed' => [
        'allowed_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'PAYMENTS_EMBED_ORIGINS',
                'http://localhost:*,http://127.0.0.1:*,https://*.webflow.io',
            )),
        ))),
    ],

    // Invoice amount limits, in cents (Stripe's minimum charge is $0.50, card maximum $999,999.99).
    'limits' => [
        'min_amount_cents' => (int) env('PAYMENTS_MIN_AMOUNT_CENTS', 50),
        'max_amount_cents' => (int) env('PAYMENTS_MAX_AMOUNT_CENTS', 99999999),
        // Stripe's per-payment ceiling applies to the total charged, so the fee counts toward it.
        'max_total_cents' => (int) env('PAYMENTS_MAX_TOTAL_CENTS', 99999999),
    ],

    /*
    | Fees are added on top of the invoice amount so the merchant nets the full
    | invoice. Percentages are in percent (2.9 = 2.9%); amounts are in cents.
    */
    'fees' => [
        'card' => [
            'percentage' => (float) env('PAYMENTS_CARD_FEE_PERCENT', 2.9),
            'fixed_cents' => (int) env('PAYMENTS_CARD_FEE_FIXED_CENTS', 30),
            // Optional ceiling on the card surcharge as a % of the invoice. Unset = full cost recovery.
            // Set to 3.0 to stay within the usual card-network limit (see README).
            'max_surcharge_percentage' => env('PAYMENTS_CARD_MAX_SURCHARGE_PERCENT') === null || env('PAYMENTS_CARD_MAX_SURCHARGE_PERCENT') === ''
                ? null
                : (float) env('PAYMENTS_CARD_MAX_SURCHARGE_PERCENT'),
        ],
        'ach' => [
            'percentage' => (float) env('PAYMENTS_ACH_FEE_PERCENT', 0.8),
            'cap_cents' => (int) env('PAYMENTS_ACH_FEE_CAP_CENTS', 500),
        ],
    ],

];
