<?php

namespace App\Models;

class Role extends BaseModel
{
    public int $id;
    public string $name = '';
    public string $label = '';
    public ?string $description = null;
    /**
     * @var array<int, string>
     */
    public array $permissions = [];
    public bool $is_system = false;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
