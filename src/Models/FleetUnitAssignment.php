<?php

namespace App\Models;

/**
 * Phase 7.1 of docs/expansion-plan.md — see database/migrations/128_fleet_units.sql.
 */
class FleetUnitAssignment extends BaseModel
{
    public const TYPE_DRIVER = 'driver';
    public const TYPE_SITE = 'site';
    public const TYPE_CUSTOMER_RENTAL = 'customer_rental';
    public const TYPE_SHOP = 'shop';

    public const ALLOWED_TYPES = [
        self::TYPE_DRIVER,
        self::TYPE_SITE,
        self::TYPE_CUSTOMER_RENTAL,
        self::TYPE_SHOP,
    ];

    public int $id = 0;
    public int $fleet_unit_id = 0;
    public string $assignment_type = self::TYPE_DRIVER;
    public ?int $assigned_user_id = null;
    public ?int $assigned_site_id = null;
    public ?int $customer_id = null;
    public string $assigned_from = '';
    public ?string $assigned_until = null;
    public ?string $notes = null;
    public int $created_by_user_id = 0;
    public ?string $created_at = null;
}
