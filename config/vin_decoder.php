<?php

/**
 * VIN Decoder Configuration
 *
 * This file configures the VIN decoding services used for vehicle lookups.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Primary Decoder
    |--------------------------------------------------------------------------
    |
    | The primary VIN decoder to use. Options: 'nhtsa', 'partstech', 'auto'
    | 'auto' will try NHTSA first, then fall back to PartsTech if configured.
    |
    */
    'primary_decoder' => env('VIN_PRIMARY_DECODER', 'nhtsa'),

    /*
    |--------------------------------------------------------------------------
    | Fallback Enabled
    |--------------------------------------------------------------------------
    |
    | Whether to fall back to secondary decoders when primary fails.
    |
    */
    'fallback_enabled' => (bool) env('VIN_FALLBACK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Decoder Priority Order
    |--------------------------------------------------------------------------
    |
    | Order in which decoders are tried when using fallback mode.
    |
    */
    'decoder_priority' => ['nhtsa', 'partstech'],

    /*
    |--------------------------------------------------------------------------
    | NHTSA Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the free NHTSA (National Highway Traffic Safety
    | Administration) VIN decoder API.
    |
    */
    'nhtsa' => [
        'enabled' => (bool) env('VIN_NHTSA_ENABLED', true),
        'api_url' => env('VIN_NHTSA_API_URL', 'https://vpic.nhtsa.dot.gov/api/vehicles/DecodeVinValues'),
        'timeout' => (int) env('VIN_NHTSA_TIMEOUT', 10),
        'user_agent' => env('VIN_NHTSA_USER_AGENT', 'PHPArm Auto Shop Management System'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PartsTech Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for the PartsTech VIN decoder (requires API key).
    |
    */
    'partstech' => [
        'enabled' => (bool) env('VIN_PARTSTECH_ENABLED', false),
        // API key is loaded from integrations.partstech.api_key setting
        'timeout' => (int) env('VIN_PARTSTECH_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Caching Configuration
    |--------------------------------------------------------------------------
    |
    | Configure how decoded VIN data is cached to reduce API calls.
    |
    */
    'cache' => [
        // Enable persistent database caching
        'enabled' => (bool) env('VIN_CACHE_ENABLED', true),

        // Cache TTL in seconds (default: 30 days)
        'ttl_seconds' => (int) env('VIN_CACHE_TTL', 2592000),

        // Also use in-memory cache for same-request deduplication
        'memory_cache' => (bool) env('VIN_MEMORY_CACHE', true),

        // Maximum cache entries (0 = unlimited)
        'max_entries' => (int) env('VIN_CACHE_MAX_ENTRIES', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting to prevent API abuse and respect external limits.
    |
    */
    'rate_limit' => [
        // Enable rate limiting
        'enabled' => (bool) env('VIN_RATE_LIMIT_ENABLED', true),

        // Maximum decodes per minute per user
        'per_user_per_minute' => (int) env('VIN_RATE_LIMIT_USER', 30),

        // Maximum decodes per minute globally
        'global_per_minute' => (int) env('VIN_RATE_LIMIT_GLOBAL', 100),

        // Burst allowance (extra requests allowed in short period)
        'burst_allowance' => (int) env('VIN_RATE_LIMIT_BURST', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for automatic retry on transient failures.
    |
    */
    'retry' => [
        // Enable automatic retries
        'enabled' => (bool) env('VIN_RETRY_ENABLED', true),

        // Maximum retry attempts
        'max_attempts' => (int) env('VIN_RETRY_MAX_ATTEMPTS', 2),

        // Initial delay between retries in milliseconds
        'initial_delay_ms' => (int) env('VIN_RETRY_INITIAL_DELAY', 500),

        // Multiplier for exponential backoff
        'backoff_multiplier' => (float) env('VIN_RETRY_BACKOFF', 2.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging Configuration
    |--------------------------------------------------------------------------
    |
    | Configure logging for VIN decode operations.
    |
    */
    'logging' => [
        // Log all decode attempts
        'log_decodes' => (bool) env('VIN_LOG_DECODES', true),

        // Log cache hits/misses
        'log_cache' => (bool) env('VIN_LOG_CACHE', false),

        // Log errors
        'log_errors' => (bool) env('VIN_LOG_ERRORS', true),

        // Log API response times
        'log_timing' => (bool) env('VIN_LOG_TIMING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Validation Settings
    |--------------------------------------------------------------------------
    |
    | Configure VIN validation behavior.
    |
    */
    'validation' => [
        // Require strict 17-character format
        'strict_format' => (bool) env('VIN_STRICT_FORMAT', true),

        // Allow short VINs (pre-1981 vehicles)
        'allow_short_vin' => (bool) env('VIN_ALLOW_SHORT', false),

        // Perform check digit validation
        'check_digit' => (bool) env('VIN_CHECK_DIGIT', false),
    ],
];
