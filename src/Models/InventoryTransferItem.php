<?php

namespace App\Models;

class InventoryTransferItem extends BaseModel
{
    public int $id;
    public int $transfer_id;
    public int $source_inventory_item_id;
    public int $destination_inventory_item_id;
    public int $quantity_requested = 0;
    public ?int $quantity_transferred = null;
    public ?string $created_at = null;

    public ?string $source_item_name = null;
    public ?string $destination_item_name = null;
    public ?string $source_item_sku = null;
    public ?string $destination_item_sku = null;
}
