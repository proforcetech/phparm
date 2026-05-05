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
 * The rules used to live in a static PHP map here (see migration 152 / commit
 * history). Migration 176 moved them to columns on `service_lines` so admins
 * can adjust them in the CP without a code change. The application still
 * whitelists the allowed FK column names in
 * {@see ServiceLineRepository::ALLOWED_SUBJECT_COLUMNS} so a typo in the admin
 * UI can't make documents impossible to validate.
 *
 * Defaulting behavior:
 *   resolveLine(null)  => null   ("no service line set" => generic; no subject required)
 *   resolveLine($id)   => row from the service_lines table, or null if not found
 *
 * Returning null from resolveLine makes validateSubject() a no-op, which is
 * the intended path for legacy/auto callers that don't carry a service_line_id.
 */
class SubjectResolver
{
    public function __construct(private readonly ServiceLineRepository $serviceLines)
    {
    }

    /**
     * Resolve a payload's `service_line_id` (or absence of one) to a ServiceLine
     * row. A missing/empty service_line_id deliberately returns null — see the
     * class docblock — so legacy callers and admin-driven generic estimates
     * skip subject validation entirely.
     */
    public function resolveLine(?int $serviceLineId): ?ServiceLine
    {
        if ($serviceLineId === null || $serviceLineId <= 0) {
            return null;
        }
        return $this->serviceLines->findById($serviceLineId);
    }

    /**
     * Validate that the document payload carries the subject FK its service line
     * requires. Throws InvalidArgumentException with a human-actionable message
     * if not. Lines with no subject_column (or with subject_required = false)
     * pass through untouched.
     *
     * @param array<string, mixed> $payload
     */
    public function validateSubject(?ServiceLine $line, array $payload): void
    {
        if ($line === null) {
            return;
        }

        $column = $line->subject_column;
        if ($column === null || $column === '' || !$line->subject_required) {
            return;
        }

        $value = $payload[$column] ?? null;
        if ($value === null || $value === '' || (int) $value <= 0) {
            $label = $line->subject_label ?? str_replace('_id', '', $column);
            throw new InvalidArgumentException(sprintf(
                "Service line '%s' requires a %s.",
                $line->slug,
                $label
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
     * Which subject FK columns does the given line care about? At most one
     * today (the line's `subject_column`) — kept as an array so the shape
     * survives if a vertical later spans multiple FKs.
     *
     * @return array<int, string>
     */
    public function subjectColumnsForLine(?ServiceLine $line): array
    {
        if ($line === null) {
            return [];
        }
        $column = $line->subject_column;
        if ($column === null || $column === '') {
            return [];
        }
        return [$column];
    }

    /**
     * The complete set of subject FK columns the application currently supports
     * across all lines. Repositories use this to pre-declare which optional
     * columns to read; backed by a DISTINCT query against service_lines so it
     * grows automatically as admins enable verticals.
     *
     * @return array<int, string>
     */
    public function allSubjectColumns(): array
    {
        return $this->serviceLines->distinctSubjectColumns();
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
