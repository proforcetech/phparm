<?php

namespace App\Models;

/**
 * Service line (trade) lookup row — Phase 11 of docs/woms-expansion-plan.md.
 *
 * Mirrors the shape of {@see Division} but represents a TRADE-shape dimension
 * (what kind of work) rather than an ORG-shape one. See migration
 * 152_service_lines.sql for the schema and seed data.
 */
class ServiceLine extends BaseModel
{
    public int $id = 0;
    public string $slug = '';
    public string $name = '';
    public ?string $description = null;
    public ?string $icon = null;
    public int $sort_order = 0;
    public bool $is_active = true;
    // Subject FK rules — moved from SubjectResolver::RULES into the table in
    // migration 176 so they're admin-adjustable. NULL subject_column means
    // "no subject FK required" (route-based or generic line).
    public ?string $subject_column = null;
    public bool $subject_required = false;
    public ?string $subject_label = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Hydrate from a raw database row. Matches the static factory pattern
     * referenced in the Phase 11 implementation spec.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
