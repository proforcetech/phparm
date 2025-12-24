<?php

namespace App\Models;

class ServiceType extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $alias = null;
    public ?string $color = null;
    public ?string $icon = null;
    public ?string $description = null;
    public bool $active = true;
    public int $display_order = 0;
}
