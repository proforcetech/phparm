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
                'users.view', 'users.invite', 'users.update',
                'customers.*', 'vehicles.*', 'estimates.*', 'invoices.*', 'payments.*', 'appointments.*',
                'inventory.*', 'inspections.*', 'warranty.*', 'reminders.*', 'bundles.*', 'time.*',
                'credit.*', 'reports.view', 'settings.view', 'notifications.view', 'service_types.*'
            ],
        ],
        'technician' => [
            'label' => 'Technician',
            'description' => 'Work estimates, inspections, jobs, and time tracking',
            'permissions' => [
                'customers.view', 'vehicles.view', 'estimates.view', 'estimates.update',
                'inspections.*', 'time.*', 'appointments.view', 'service_types.view'
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
