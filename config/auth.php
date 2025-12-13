<?php

return [
    'passwords' => [
        'expire_minutes' => 60,
        'throttle_minutes' => 2,
        'min_length' => 12,
    ],
    'verification' => [
        'require_staff_verification' => false,
        'require_customer_verification' => true,
        'token_ttl_hours' => 48,
    ],
    'jwt' => [
        // Secret key for signing JWT tokens (min 32 characters)
        // In production, use a strong random key from environment variable
        'secret' => env('JWT_SECRET', 'your-256-bit-secret-key-change-in-production'),
        // Access token lifetime in seconds (default: 1 hour)
        'ttl' => (int) env('JWT_TTL', 3600),
        // Refresh token lifetime in seconds (default: 7 days)
        'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 604800),
    ],
    'roles' => [
        'admin' => [
            'label' => 'Admin',
            'description' => 'Full control across all modules',
            'permissions' => ['*'],
        ],
        'manager' => [
            'label' => 'Manager',
            'description' => 'Manage shop operations, estimates, invoices, schedules, inventory',
            'permissions' => [
                'users.view', 'users.create', 'users.update', 'users.delete', 'users.invite',
                'customers.*', 'vehicles.*', 'estimates.*', 'invoices.*', 'payments.*', 'appointments.*',
                'inventory.*', 'inspections.*', 'warranty.*', 'reminders.*', 'bundles.*', 'time.*',
                'credit.*', 'reports.view', 'settings.view', 'notifications.view', 'service_types.*',
                // Full CMS access (matches admin for CMS operations)
                'cms.*'
            ],
        ],
        'technician' => [
            'label' => 'Technician',
            'description' => 'Work estimates, inspections, jobs, and time tracking',
            'permissions' => [
                'customers.view', 'vehicles.view', 'estimates.view', 'estimates.create', 'estimates.update',
                'inspections.*', 'time.*', 'appointments.view', 'service_types.view',
                // CMS content editing (no administrative settings)
                'cms.pages.view', 'cms.pages.create', 'cms.pages.update', 'cms.pages.delete',
                'cms.menus.view', 'cms.menus.create', 'cms.menus.update', 'cms.menus.delete',
                'cms.media.view', 'cms.media.create', 'cms.media.update', 'cms.media.delete',
                'cms.components.view', 'cms.components.create', 'cms.components.update', 'cms.components.delete',
                'cms.dashboard.view',
                'cms.templates.view'
            ],
        ],
        'customer' => [
            'label' => 'Customer',
            'description' => 'Customer portal scoped to their profile and documents',
            'permissions' => [
                'portal.profile', 'portal.vehicles', 'portal.estimates', 'portal.invoices', 'portal.warranty', 'portal.reminders'
            ],
        ],
    ],
    'customer_portal' => [
        'login_enabled' => true,
        'auto_link_on_import' => true,
        'allow_registration' => false,
    ],
];
