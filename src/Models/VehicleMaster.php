<?php

namespace App\Models;

class VehicleMaster extends BaseModel
{
    public int $id;
    public int $year;
    public string $make;
    public string $model;
    public string $engine;
    public string $transmission;
    public string $drive;
    public ?string $trim = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
