<?php

namespace App\Models;

class InspectionReport extends BaseModel
{
    public int $id;
    public int $template_id;
    public int $customer_id;
    public ?int $vehicle_id = null;
    public ?int $estimate_id = null;
    public ?int $appointment_id = null;
    public string $status;
    public ?string $summary = null;
    public ?string $pdf_path = null;
    public ?int $completed_by = null;
    public ?string $completed_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
