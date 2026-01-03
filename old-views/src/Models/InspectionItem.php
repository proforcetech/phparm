<?php

namespace App\Models;

class InspectionItem extends BaseModel
{
    public int $id;
    public int $section_id;
    public string $name;
    public string $input_type;
    public ?string $default_value = null;
    public int $display_order = 0;
}
