<?php

namespace App\Services\ServiceLine;

use App\Models\ServiceLine;
use InvalidArgumentException;

/**
 * Maps a service line to the FK column on appointments / estimates / workorders /
 * invoices that holds the "subject" of that document — i.e. *what* the work is
 * being done to. For automotive the subject is a customer_vehicle (vehicle_id);
 * for property/building work it is a site_asset (site_asset_id); future verticals
 * add their own column under the same pattern.
 *
 * The rules are intentionally a static PHP map rather than a `service_line_rules`
 * DB table: the WO type vocabulary on Workorder::ALLOWED_TYPES is treated the
 * same way (Phase 11), and adding a vertical is a code change that ships with
 * supporting service-layer behavior anyway. Keeping rules in code keeps them
 * versioned with the validators that depend on them.
 *
 * Rule shape per slug: `column` is the FK column the document write must
 * populate; `required` is whether that column may be NULL after validation.
 * NULL `column` means "no subject FK is required for this line" — used by
 * lines like commercial_cleaning where the route, not a single asset, is the
 * subject (route binding lives on a different table).
 */
class SubjectResolver
{
    public const DEFAULT_LINE_SLUG = 'auto_repair';

    /**
     * @var array<string, array{column: ?string, required: bool}>
     */
    private const RULES = [
        'auto_repair'         => ['column' => 'vehicle_id',    'required' => true],
        'fleet_management'    => ['column' => 'vehicle_id',    'required' => true],
        'building_repair'     => ['column' => 'site_asset_id', 'required' => false],
        'property_management' => ['column' => 'site_asset_id', 'required' => true],
        'equipment_repair'    => ['column' => 'site_asset_id', 'required' => true],
        'it_support'          => ['column' => 'site_asset_id', 'required' => false],
        'security_systems'    => ['column' => 'site_asset_id', 'required' => false],
        'pos_support'         => ['column' => 'site_asset_id', 'required' => false],
        'commercial_cleaning' => ['column' => null,            'required' => false],
    ];

    public function __construct(private readonly ServiceLineRepository $serviceLines)
    {
    }

    /**
     * Resolve a payload's `service_line_id` (or absence of one) to a ServiceLine
     * row, defaulting to auto_repair so legacy automotive callers don't have to
     * pass anything new. Returns null only if even the default line is missing,
     * which would mean migration 152 hasn't run.
     */
    public function resolveLine(?int $serviceLineId): ?ServiceLine
    {
        if ($serviceLineId !== null) {
            return $this->serviceLines->findById($serviceLineId);
        }

        return $this->serviceLines->findBySlug(self::DEFAULT_LINE_SLUG);
    }

    /**
     * Validate that the document payload carries the subject FK its service line
     * requires. Throws InvalidArgumentException with a human-actionable message
     * if not. A line we don't recognize is treated as "no subject required" so
     * adding a service_lines row from the admin UI doesn't immediately break
     * every create call until code ships.
     *
     * @param array<string, mixed> $payload
     */
    public function validateSubject(?ServiceLine $line, array $payload): void
    {
        if ($line === null) {
            return;
        }

        $rule = self::RULES[$line->slug] ?? null;
        if ($rule === null || $rule['column'] === null || !$rule['required']) {
            return;
        }

        $value = $payload[$rule['column']] ?? null;
        if ($value === null || $value === '' || (int) $value <= 0) {
            throw new InvalidArgumentException(sprintf(
                "Service line '%s' requires a %s.",
                $line->slug,
                str_replace('_id', '', $rule['column'])
            ));
        }
    }

    /**
     * Extract the subject FK columns this service line knows about, normalized
     * to int|null so callers can splice them straight into bind arrays without
     * worrying about empty-string vs zero. Columns belonging to *other* lines
     * are left out — e.g. a property workorder write does not pass vehicle_id
     * even if the request body happened to include it.
     *
     * @param array<string, mixed> $payload
     * @return array<string, ?int>
     */
    public function extractSubjectColumns(?ServiceLine $line, array $payload): array
    {
        $columns = $this->subjectColumnsForLine($line);
        $out = [];
        foreach ($columns as $column) {
            $out[$column] = $this->normalizeId($payload[$column] ?? null);
        }
        return $out;
    }

    /**
     * Inverse of extractSubjectColumns: which columns does the *current* row
     * have, regardless of which line it claims? Used by repositories when
     * deciding which subject relation to eager-load. Order matches RULES so
     * the line's "primary" column comes first.
     *
     * @return array<int, string>
     */
    public function subjectColumnsForLine(?ServiceLine $line): array
    {
        if ($line === null) {
            return [];
        }
        $rule = self::RULES[$line->slug] ?? null;
        if ($rule === null || $rule['column'] === null) {
            return [];
        }
        return [$rule['column']];
    }

    /**
     * The complete set of subject FK columns the application currently supports
     * across all lines. Repositories use this to pre-declare which optional
     * columns to read; new vertical FKs are added here as they're rolled out.
     *
     * @return array<int, string>
     */
    public static function allSubjectColumns(): array
    {
        $cols = [];
        foreach (self::RULES as $rule) {
            if ($rule['column'] !== null && !in_array($rule['column'], $cols, true)) {
                $cols[] = $rule['column'];
            }
        }
        return $cols;
    }

    private function normalizeId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
