<?php

/**
 * Payment Gateway Configuration
 *
 * Configure your payment gateways here.
 * For production, use environment variables for sensitive credentials.
 */

return [
    /**
     * Default payment gateway
     * Options: stripe, square, paypal
     */
    'default' => env('PAYMENT_DEFAULT_GATEWAY', 'stripe'),

    /**
     * Stripe Configuration
     * Get your keys from: https://dashboard.stripe.com/apikeys
     */
    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY', ''),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY', ''),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET', ''),
        'test_mode' => env('STRIPE_TEST_MODE', true),
    ],

    /**
     * Square Configuration
     * Get your keys from: https://developer.squareup.com/apps
     */
    'square' => [
        'access_token' => env('SQUARE_ACCESS_TOKEN', ''),
        'location_id' => env('SQUARE_LOCATION_ID', ''),
        'webhook_signature_key' => env('SQUARE_WEBHOOK_SIGNATURE_KEY', ''),
        'production' => env('SQUARE_PRODUCTION', false),
    ],

    /**
     * PayPal Configuration
     * Get your credentials from: https://developer.paypal.com/dashboard/applications
     */
    'paypal' => [
        'client_id' => env('PAYPAL_CLIENT_ID', ''),
        'client_secret' => env('PAYPAL_CLIENT_SECRET', ''),
        'webhook_id' => env('PAYPAL_WEBHOOK_ID', ''),
        'sandbox' => env('PAYPAL_SANDBOX', true),
    ],

    /**
     * Payment URLs
     */
    'urls' => [
        'success' => env('APP_URL', 'http://localhost') . '/payment/success',
        'cancel' => env('APP_URL', 'http://localhost') . '/payment/cancel',
        'webhook_base' => env('APP_URL', 'http://localhost') . '/api/webhooks/payments',
    ],

    /**
     * Currency
     */
    'currency' => env('PAYMENT_CURRENCY', 'USD'),

    /**
     * Enable/disable gateways
     */
    'enabled' => [
        'stripe' => env('STRIPE_ENABLED', true),
        'square' => env('SQUARE_ENABLED', false),
        'paypal' => env('PAYPAL_ENABLED', false),
    ],
];
