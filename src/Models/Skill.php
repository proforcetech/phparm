<?php

namespace App\Models;

/**
 * Skill catalog row — Phase 17 / S11 of docs/woms-expansion-plan.md.
 *
 * A skill is a named competency a technician can hold. Each skill is normally
 * scoped to one service line (e.g. "Auto Diagnostics" lives under auto_repair)
 * but service_line_id is nullable so cross-trade competencies like "First Aid"
 * or "Customer Service" can be tracked too.
 *
 * See migration 167_technician_skill_matrix.sql.
 */
class Skill extends BaseModel
{
    public int $id = 0;
    public string $slug = '';
    public string $name = '';
    public ?string $description = null;
    public ?int $service_line_id = null;
    public ?string $category = null;
    public int $sort_order = 0;
    public bool $is_active = true;
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
