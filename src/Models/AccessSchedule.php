<?php

namespace App\Models;

/**
 * Recurring access policy — Phase 16 / S1 of docs/woms-expansion-plan.md.
 *
 * Reusable time-window template referenced from credential_doors so the same
 * credential can follow different schedules at different doors. Same-day
 * windows only — cross-midnight is two rows ("21:00-23:59" + "00:00-06:00").
 *
 * See migration 166_security_pos_verticals.sql.
 */
class AccessSchedule extends BaseModel
{
    public const ALLOWED_DAY_CODES = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public int $id = 0;
    public int $customer_id = 0;
    public string $name = '';
    public ?string $description = null;
    public string $days_of_week = '';
    public string $start_time = '00:00:00';
    public string $end_time = '23:59:59';
    public ?string $timezone = null;
    public bool $is_active = true;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Decode the days_of_week comma list into normalized lowercase codes.
     *
     * @return array<int, string>
     */
    public function days(): array
    {
        if ($this->days_of_week === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $this->days_of_week) as $piece) {
            $code = strtolower(trim($piece));
            if (in_array($code, self::ALLOWED_DAY_CODES, true)) {
                $out[$code] = $code;
            }
        }
        return array_values($out);
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }
}
