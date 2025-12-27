<?php

namespace App\Models;

class InventoryVehicleCompatibility extends BaseModel
{
    public int $id;
    public int $inventory_item_id;
    public int $vehicle_master_id;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
