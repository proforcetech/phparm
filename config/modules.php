<?php

/**
 * Module Configuration
 *
 * Defines all toggleable application modules with their metadata,
 * permission mappings, and UI integration points.
 *
 * Modules can be enabled/disabled at the shop level via module_settings table.
 * User groups can also restrict access to specific modules.
 */

return [
    // Core module cannot be disabled
    'protected_modules' => ['core'],

    'modules' => [
        'core' => [
            'label' => 'Core Operations',
            'description' => 'Essential shop operations including customers, vehicles, and dashboard',
            'icon' => 'building-storefront',
            'default_enabled' => true,
            'can_disable' => false,
            'permissions_prefix' => ['customers.*', 'vehicles.*', 'dashboard.*'],
            'routes' => ['/cp/dashboard', '/cp/customers', '/cp/vehicles'],
            'sidebar_keys' => ['dashboard', 'customers', 'vehicles'],
            'depends_on' => [],
        ],

        'estimates' => [
            'label' => 'Estimates',
            'description' => 'Create and manage customer estimates and quotes',
            'icon' => 'document-text',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['estimates.*'],
            'routes' => ['/cp/estimates'],
            'sidebar_keys' => ['estimates'],
            'depends_on' => ['core'],
        ],

        'workorders' => [
            'label' => 'Work Orders',
            'description' => 'Job tracking, technician assignments, and workflow management',
            'icon' => 'wrench-screwdriver',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['workorders.*'],
            'routes' => ['/cp/workorders'],
            'sidebar_keys' => ['workorders'],
            'depends_on' => ['core'],
        ],

        'invoicing' => [
            'label' => 'Invoicing & Payments',
            'description' => 'Invoice generation, payment processing, and financial records',
            'icon' => 'currency-dollar',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['invoices.*', 'payments.*'],
            'routes' => ['/cp/invoices'],
            'sidebar_keys' => ['invoices'],
            'depends_on' => ['core'],
        ],

        'appointments' => [
            'label' => 'Appointments',
            'description' => 'Scheduling, calendar management, and availability tracking',
            'icon' => 'calendar-days',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['appointments.*'],
            'routes' => ['/cp/appointments'],
            'sidebar_keys' => ['appointments'],
            'depends_on' => ['core'],
        ],

        'inventory' => [
            'label' => 'Inventory Management',
            'description' => 'Parts catalog, stock levels, purchase orders, and vendor management',
            'icon' => 'cube',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['inventory.*'],
            'routes' => ['/cp/inventory'],
            'sidebar_keys' => ['inventory'],
            'depends_on' => ['core'],
        ],

        'towing' => [
            'label' => 'Towing & Roadside',
            'description' => 'Dispatch management, driver assignments, roadside assistance, and towing pricing',
            'icon' => 'truck',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['dispatch.*', 'roadside.*', 'towing.*'],
            'routes' => ['/cp/dispatch', '/cp/towing'],
            'sidebar_keys' => ['dispatch', 'towing'],
            'depends_on' => ['core'],
        ],

        'impound' => [
            'label' => 'Impound & Storage',
            'description' => 'Vehicle storage, lien processing, auctions, and impound management',
            'icon' => 'lock-closed',
            'default_enabled' => false,
            'can_disable' => true,
            'permissions_prefix' => ['impound.*', 'storage.*'],
            'routes' => ['/cp/impound'],
            'sidebar_keys' => ['impound'],
            'depends_on' => ['core'],
        ],

        'inspections' => [
            'label' => 'Inspections',
            'description' => 'Vehicle inspection templates, checklists, and reports',
            'icon' => 'clipboard-document-check',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['inspections.*'],
            'routes' => ['/cp/inspections'],
            'sidebar_keys' => ['inspections'],
            'depends_on' => ['core'],
        ],

        'warranty' => [
            'label' => 'Warranty Claims',
            'description' => 'Warranty tracking, claims processing, and vendor reimbursements',
            'icon' => 'shield-check',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['warranty.*'],
            'routes' => ['/cp/warranty'],
            'sidebar_keys' => ['warranty'],
            'depends_on' => ['core'],
        ],

        'time_tracking' => [
            'label' => 'Time Tracking',
            'description' => 'Technician time entries, clock in/out, and labor reporting',
            'icon' => 'clock',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['time.*'],
            'routes' => ['/cp/time', '/cp/time-logs'],
            'sidebar_keys' => ['time', 'time-logs'],
            'depends_on' => ['core'],
        ],

        'messaging' => [
            'label' => 'Messaging',
            'description' => 'Customer SMS/email messaging and notification management',
            'icon' => 'chat-bubble-left-right',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['messages.*', 'notifications.*'],
            'routes' => ['/cp/messages'],
            'sidebar_keys' => ['messages'],
            'depends_on' => ['core'],
        ],

        'reminders' => [
            'label' => 'Reminder Campaigns',
            'description' => 'Automated service reminders and marketing campaigns',
            'icon' => 'bell-alert',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['reminders.*'],
            'routes' => ['/cp/reminders'],
            'sidebar_keys' => ['reminders'],
            'depends_on' => ['core', 'messaging'],
        ],

        'cms' => [
            'label' => 'Content Management',
            'description' => 'Website pages, menus, templates, and content blocks',
            'icon' => 'globe-alt',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['cms.*'],
            'routes' => ['/cp/cms'],
            'sidebar_keys' => ['cms', 'cms-dashboard', 'cms-pages', 'cms-categories', 'cms-menus', 'cms-components', 'cms-templates', 'cms-404'],
            'depends_on' => [],
        ],

        'customer_portal' => [
            'label' => 'Customer Portal',
            'description' => 'Customer self-service portal for viewing invoices, appointments, and vehicles',
            'icon' => 'user-circle',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['portal.*'],
            'routes' => ['/portal'],
            'sidebar_keys' => [],
            'depends_on' => ['core'],
        ],

        'reports' => [
            'label' => 'Reports & Analytics',
            'description' => 'Business reporting, analytics dashboards, and data exports',
            'icon' => 'chart-bar',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['reports.*'],
            'routes' => ['/cp/reports'],
            'sidebar_keys' => ['reports'],
            'depends_on' => ['core'],
        ],

        'bundles' => [
            'label' => 'Service Bundles',
            'description' => 'Pre-configured service packages and bundle pricing',
            'icon' => 'squares-plus',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['bundles.*'],
            'routes' => ['/cp/bundles'],
            'sidebar_keys' => ['bundles'],
            'depends_on' => ['core'],
        ],

        'financial' => [
            'label' => 'Financial Management',
            'description' => 'Vendor management, purchase tracking, and expense reporting',
            'icon' => 'banknotes',
            'default_enabled' => true,
            'can_disable' => true,
            'permissions_prefix' => ['financial.*', 'vendors.*', 'purchases.*'],
            'routes' => ['/cp/financial'],
            'sidebar_keys' => ['financial'],
            'depends_on' => ['core'],
        ],
    ],

    // Default modules for new installations
    'default_enabled' => [
        'core',
        'estimates',
        'workorders',
        'invoicing',
        'appointments',
        'inventory',
        'inspections',
        'time_tracking',
        'reports',
    ],

    // Modules that require additional configuration before enabling
    'requires_setup' => [
        'towing' => ['dispatch_settings', 'driver_tokens'],
        'impound' => ['storage_rates', 'lien_templates'],
        'messaging' => ['twilio_credentials'],
    ],
];
