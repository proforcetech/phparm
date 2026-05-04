<?php

namespace App\Models;

/**
 * Phase 18 / C2 — A single proof-of-delivery / photo / signature attached
 * to a subcontractor_assignments row by the sub through the self-service
 * portal. kind narrows the UI grouping; `note` rows have no file payload.
 */
class SubcontractorAssignmentPod extends BaseModel
{
    public const KIND_POD = 'pod';
    public const KIND_PHOTO = 'photo';
    public const KIND_SIGNATURE = 'signature';
    public const KIND_NOTE = 'note';

    public const KINDS = [
        self::KIND_POD,
        self::KIND_PHOTO,
        self::KIND_SIGNATURE,
        self::KIND_NOTE,
    ];

    public int $id = 0;
    public int $assignment_id = 0;
    public int $subcontractor_id = 0;
    public string $kind = self::KIND_POD;
    public ?string $original_name = null;
    public ?string $stored_path = null;
    public ?string $mime_type = null;
    public ?int $size_bytes = null;
    public ?string $sha256 = null;
    public ?string $notes = null;
    public ?int $uploaded_via_token_id = null;
    public ?string $uploaded_at = null;
    public ?string $deleted_at = null;
}
