<?php

namespace App\Models;

class InventoryLookup extends BaseModel
{
    public int $id;
    public string $type;
    public string $name;
    public ?string $description = null;
    public bool $is_parts_supplier = false;
}
