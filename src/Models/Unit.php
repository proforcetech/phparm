<?php

namespace App\Models;

/**
 * Leasable space within a `sites` row — Phase 12 of
 * docs/woms-expansion-plan.md (Property Management vertical). See migration
 * 157_property_management_tenants_units_leases.sql for the schema.
 *
 * A property-mgmt customer's site IS a building; its units are the leasable
 * spaces (apartments, suites, retail bays). Other verticals leave units empty.
 */
class Unit extends BaseModel
{
    public int $id = 0;
    public int $site_id = 0;
    public string $code = '';
    public ?string $name = null;
    public string $unit_type = 'commercial';
    public ?string $floor = null;
    public ?int $square_feet = null;
    public ?int $bedrooms = null;
    public ?float $bathrooms = null;
    public string $status = 'active';
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
