<?php

namespace App\Models;

class InspectionTemplate extends BaseModel
{
    public int $id;
    public string $name;
    public ?string $description = null;
    public bool $active = true;
}
