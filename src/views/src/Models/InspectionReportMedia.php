<?php

namespace App\Models;

class InspectionReportMedia extends BaseModel
{
    public int $id;
    public int $report_id;
    public string $type;
    public string $path;
    public string $mime_type;
    public ?int $uploaded_by = null;
    public ?string $created_at = null;
}
