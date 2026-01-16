<?php

namespace App\Models;

class Employee extends BaseModel
{
    public int $id;
    public int $user_id;
    public ?string $hire_date = null;
    public ?string $emergency_contact = null;
    public ?string $pay_structure = null;
    /**
     * @var array<int, string>|null
     */
    public ?array $skills = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
