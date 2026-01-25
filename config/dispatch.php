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
        'distance' => 0.30,
        'equipment' => 0.23,
        'shift' => 0.14,
        'eta' => 0.13,
        'performance' => 0.10,
        'deadhead' => 0.10,
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

    /*
    |--------------------------------------------------------------------------
    | Load Balancing Configuration
    |--------------------------------------------------------------------------
    |
    | Configure driver load balancing parameters for fair work distribution.
    |
    */
    'load_balancing' => [
        // Workload limits - prevent driver overload
        'workload' => [
            // Maximum concurrent jobs a driver can have
            'max_concurrent_jobs' => (int) env('DISPATCH_MAX_CONCURRENT_JOBS', 3),

            // Limit mode: 'hard' excludes drivers at limit, 'soft' penalizes their score
            'limit_mode' => env('DISPATCH_WORKLOAD_LIMIT_MODE', 'soft'),

            // Score penalty multiplier when in soft mode (0.0 to 1.0)
            'soft_limit_penalty' => (float) env('DISPATCH_SOFT_LIMIT_PENALTY', 0.5),
        ],

        // Dispatch strategy: how to select drivers
        // 'highest_score' - Traditional scoring (default)
        // 'round_robin' - Rotate through available drivers
        // 'balanced' - Hybrid approach balancing score and fairness
        'strategy' => env('DISPATCH_STRATEGY', 'highest_score'),

        // Weight for fairness scoring (0.0 to 1.0)
        'fairness_weight' => (float) env('DISPATCH_FAIRNESS_WEIGHT', 0.20),

        // Acceptance rate configuration
        'acceptance_rate' => [
            // Weight for acceptance rate in scoring
            'weight' => (float) env('DISPATCH_ACCEPTANCE_RATE_WEIGHT', 0.05),

            // Minimum acceptance rate threshold (null = no minimum)
            'minimum_threshold' => env('DISPATCH_MIN_ACCEPTANCE_RATE') !== null
                ? (float) env('DISPATCH_MIN_ACCEPTANCE_RATE')
                : null,

            // Days to look back for acceptance rate calculation
            'lookback_days' => (int) env('DISPATCH_ACCEPTANCE_LOOKBACK_DAYS', 30),
        ],

        // Job priority levels configuration
        'job_priority' => [
            // Enable priority-based dispatch adjustments
            'enabled' => (bool) env('DISPATCH_JOB_PRIORITY_ENABLED', true),

            // Priority level definitions
            'levels' => [
                'normal' => [
                    'timeout_multiplier' => 1.0,
                    'queue_depth_multiplier' => 1.0,
                ],
                'high' => [
                    'timeout_multiplier' => 0.75,
                    'queue_depth_multiplier' => 1.5,
                ],
                'urgent' => [
                    'timeout_multiplier' => 0.5,
                    'queue_depth_multiplier' => 2.0,
                ],
                'vip' => [
                    'timeout_multiplier' => 0.5,
                    'queue_depth_multiplier' => 2.5,
                    'skip_rotation' => true,
                ],
            ],
        ],

        // Fair distribution tracking
        'fair_distribution' => [
            // Enable fair distribution tracking
            'enabled' => (bool) env('DISPATCH_FAIR_DISTRIBUTION_ENABLED', true),

            // Hours to look back for offer tracking
            'tracking_window_hours' => (int) env('DISPATCH_TRACKING_WINDOW_HOURS', 24),

            // Minimum offers to guarantee per driver per window
            'min_offers_guarantee' => (int) env('DISPATCH_MIN_OFFERS_GUARANTEE', 5),

            // Score bonus for under-offered drivers (0.0 to 1.0)
            'under_offered_bonus' => (float) env('DISPATCH_UNDER_OFFERED_BONUS', 0.15),
        ],
    ],
];
