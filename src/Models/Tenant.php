<?php

namespace App\Models;

/**
 * Lessee entity (individual or business) — Phase 12 of
 * docs/woms-expansion-plan.md. See migration
 * 157_property_management_tenants_units_leases.sql for the schema.
 *
 * `company_id` optionally points to a `companies` row when the tenant is a
 * business we already track as a customer. `portal_user_id` links to a
 * `users` row when the tenant has been granted portal access (separate from
 * site_contacts which are landlord-side staff).
 */
class Tenant extends BaseModel
{
    public int $id = 0;
    public ?int $company_id = null;
    public ?int $portal_user_id = null;
    public string $entity_type = 'individual';
    public string $display_name = '';
    public ?string $primary_email = null;
    public ?string $primary_phone = null;
    public ?string $secondary_phone = null;
    public string $status = 'active';
    public ?string $move_in_date = null;
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
