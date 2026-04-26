<?php

namespace App\Models;

class AssetDocument extends BaseModel
{
    public int $id = 0;
    public int $asset_id = 0;
    public string $doc_type = 'other';
    public string $filename = '';
    public ?string $original_filename = null;
    public ?string $mime_type = null;
    public ?int $size_bytes = null;
    public string $storage_path = '';
    public ?string $storage_disk = null;
    public ?string $notes = null;
    public ?int $uploaded_by = null;
    public ?string $uploaded_at = null;
}
