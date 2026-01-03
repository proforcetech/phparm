<?php

namespace App\Models;

class InspectionReportItem extends BaseModel
{
    public int $id;
    public int $report_id;
    public int $template_item_id;
    public string $label;
    public string $response;
    public ?string $note = null;
    public ?string $created_at = null;
}
