<?php

/**
 * Dispatch Configuration
 *
 * Configuration for the dispatch system including ETA providers,
 * waterfall settings, and geofencing parameters.
 */

return [
    /*
    |--------------------------------------------------------------------------
    | ETA Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the mapping API provider for real-time ETA calculations.
    | Supported providers: 'google', 'mapbox', null (use estimation)
    |
    */
    'eta' => [
        'provider' => env('DISPATCH_ETA_PROVIDER', null), // 'google' or 'mapbox'
        'api_key' => env('DISPATCH_ETA_API_KEY', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Waterfall Dispatch Settings
    |--------------------------------------------------------------------------
    |
    | Configure the automated waterfall dispatch behavior.
    |
    */
    'waterfall' => [
        // Default timeout for each offer in seconds
        'offer_timeout_seconds' => (int) env('DISPATCH_OFFER_TIMEOUT', 60),

        // Maximum number of drivers to attempt before giving up
        'max_offers' => (int) env('DISPATCH_MAX_OFFERS', 10),

        // Whether to automatically start waterfall on new jobs
        'auto_initiate' => (bool) env('DISPATCH_AUTO_WATERFALL', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geofencing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure geofence detection and automatic state transitions.
    |
    */
    'geofencing' => [
        // Default radius in meters for job site geofences
        'default_radius_meters' => (int) env('DISPATCH_GEOFENCE_RADIUS', 200),

        // How often to check geofences (cron interval in seconds)
        'check_interval_seconds' => 30,

        // Automatically create geofences when jobs are dispatched
        'auto_create' => (bool) env('DISPATCH_AUTO_GEOFENCE', true),

        // Actions to trigger on geofence events
        'enter_action' => 'mark_arrived',
        'exit_action' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idle Detection Configuration
    |--------------------------------------------------------------------------
    |
    | Configure idle driver detection thresholds.
    |
    */
    'idle_detection' => [
        // Minutes of no movement before alerting
        'threshold_minutes' => (int) env('DISPATCH_IDLE_THRESHOLD', 15),

        // Minimum movement in degrees to be considered "moving"
        'movement_threshold_degrees' => 0.0005, // ~50 meters

        // Whether to enable idle alerts
        'enabled' => (bool) env('DISPATCH_IDLE_ALERTS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recommendation Weights
    |--------------------------------------------------------------------------
    |
    | Adjust the weights used in driver recommendation scoring.
    | Values should sum to 1.0.
    |
    */
    'recommendation_weights' => [
        'distance' => 0.35,
        'equipment' => 0.25,
        'shift' => 0.15,
        'eta' => 0.15,
        'performance' => 0.10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hard Filters
    |--------------------------------------------------------------------------
    |
    | Configure which filters are enforced strictly (exclude drivers)
    | vs. soft (penalize in scoring).
    |
    */
    'hard_filters' => [
        'equipment_compatibility' => true,
        'certifications' => true,
        'shift_hours' => false, // Just penalize, don't exclude
    ],

    /*
    |--------------------------------------------------------------------------
    | Heatmap Configuration
    |--------------------------------------------------------------------------
    |
    | Configure job density heatmap generation.
    |
    */
    'heatmap' => [
        // Grid resolution in degrees (0.01 ≈ 1km)
        'grid_size' => 0.01,

        // Days of data to retain
        'retention_days' => 90,
    ],
];
