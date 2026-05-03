<?php

namespace App\Models;

/**
 * Verification photo attached to a route visit — Phase 15 / M7+S8 of
 * docs/woms-expansion-plan.md.
 *
 * EXIF + perceptual_hash are extracted at upload so the S8 verification
 * heuristics (#139) — duplicate detection, time-window check, geo-fence
 * check — index without re-opening the file.
 *
 * See migration 165_recurring_service_routes.sql.
 */
class RouteVisitPhoto extends BaseModel
{
    public int $id = 0;
    public int $route_visit_id = 0;
    public ?int $uploaded_by_user_id = null;
    public string $file_path = '';
    public ?string $file_mime = null;
    public ?int $file_size_bytes = null;
    public ?string $exif_taken_at = null;
    public ?string $exif_lat = null;
    public ?string $exif_lng = null;
    public ?string $perceptual_hash = null;
    public ?string $caption = null;
    public ?string $uploaded_at = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
