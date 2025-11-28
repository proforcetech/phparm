<?php

namespace App\Models;

class InspectionSection extends BaseModel
{
    public int $id;
    public int $template_id;
    public string $name;
    public int $display_order = 0;
}
