<?php

namespace App\Models;

class SiteAsset extends BaseModel
{
    public int $id = 0;
    public int $site_id = 0;
    public ?int $division_id = null;
    public ?int $asset_type_id = null;
    public ?int $parent_asset_id = null;
    public string $name = '';
    public ?string $code = null;
    public string $status = 'active';
    public ?string $install_date = null;
    public ?string $decommissioned_at = null;
    public ?string $notes = null;
    // Phase 2.2 of docs/expansion-plan.md: identity metadata.
    public ?string $manufacturer = null;
    public ?string $model_number = null;
    public ?string $serial_number = null;
    public ?string $vendor = null;
    public ?string $warranty_start = null;
    public ?string $warranty_end = null;
    public ?int $purchase_cents = null;
    public ?array $custom_fields = null;
    // Phase 2.3 of docs/expansion-plan.md: public scan token (opaque hex).
    public ?string $qr_token = null;
    // Phase 2.4 of docs/expansion-plan.md: floorplan + network identity.
    public ?string $building = null;
    public ?string $floor = null;
    public ?string $room = null;
    public ?string $rack = null;
    public ?string $rack_position = null;
    public ?string $ip_address = null;
    public ?string $mac_address = null;
    public ?string $subnet = null;
    public ?string $vlan = null;
    // Phase 2.5 of docs/expansion-plan.md: lifecycle + condition scoring.
    public ?int $condition_score = null;
    public ?float $expected_life_years = null;
    public ?int $replacement_estimate_cents = null;
    public ?string $last_inspected_at = null;
    public ?string $replace_by_date = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
