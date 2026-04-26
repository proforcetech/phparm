<?php

namespace App\Models;

/**
 * Phase 6.6 of docs/expansion-plan.md — see database/migrations/125_portal_uploads.sql.
 */
class PortalUpload extends BaseModel
{
    public int $id = 0;
    public int $portal_account_id = 0;
    public int $company_id = 0;
    public string $entity_type = '';
    public int $entity_id = 0;
    public string $original_name = '';
    public string $stored_path = '';
    public string $mime_type = '';
    public int $size_bytes = 0;
    public string $sha256 = '';
    public int $uploaded_by_user_id = 0;
    public ?string $created_at = null;
    public ?string $deleted_at = null;
}
