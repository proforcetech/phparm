<?php

namespace App\Models;

/**
 * One stop on a recurring service route — Phase 15 / M7 of
 * docs/woms-expansion-plan.md.
 *
 * sequence is unique per route so the mobile app renders stops in the same
 * order every visit. site_asset_id is optional — most stops are site-level
 * but some (e.g. the cooler at unit 4) need pinning.
 *
 * required_photos overrides the route default when non-NULL.
 *
 * See migration 165_recurring_service_routes.sql.
 */
class RouteStop extends BaseModel
{
    public int $id = 0;
    public int $service_route_id = 0;
    public int $sequence = 0;
    public int $site_id = 0;
    public ?int $site_asset_id = null;
    public ?string $stop_name = null;
    public int $estimated_minutes = 15;
    public ?int $checklist_template_id = null;
    public ?int $required_photos = null;
    public ?string $notes = null;
    public bool $is_active = true;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Resolve the per-visit photo requirement, falling back to the route
     * default. Used by the completion guard in RouteVisitService.
     */
    public function effectiveRequiredPhotos(ServiceRoute $route): int
    {
        if ($this->required_photos !== null) {
            return max(0, $this->required_photos);
        }
        return max(0, $route->min_photos_per_visit);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
