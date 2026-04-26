<?php

namespace App\Models;

/**
 * Phase 9.2 — tunable lifecycle scoring weights + thresholds + inflation rate.
 * Resolved per-division (with global fallback) by CapitalScoringModelRepository.
 *
 * Weights are decimals stored to 3 places; service layer normalizes to a sum
 * of 1.0 at read time so a hand-edited row can't bypass the 0–100 risk cap.
 *
 * Thresholds are *inclusive lower bounds* for the named category. Anything
 * with replace_by_date already in the past is unconditionally categorized
 * as urgent, regardless of these numbers.
 */
class CapitalScoringModel extends BaseModel
{
    public const DEFAULT_CONDITION_WEIGHT = 0.500;
    public const DEFAULT_AGE_WEIGHT = 0.300;
    public const DEFAULT_REPLACE_BY_WEIGHT = 0.200;
    public const DEFAULT_WATCH_THRESHOLD = 40.0;
    public const DEFAULT_ACTION_THRESHOLD = 60.0;
    public const DEFAULT_URGENT_THRESHOLD = 80.0;
    public const DEFAULT_INFLATION_RATE = 0.0300;

    public int $id = 0;
    public ?int $division_id = null;
    public string $name = '';
    public bool $is_default = false;
    public float $condition_weight = self::DEFAULT_CONDITION_WEIGHT;
    public float $age_weight = self::DEFAULT_AGE_WEIGHT;
    public float $replace_by_weight = self::DEFAULT_REPLACE_BY_WEIGHT;
    public float $watch_threshold = self::DEFAULT_WATCH_THRESHOLD;
    public float $action_threshold = self::DEFAULT_ACTION_THRESHOLD;
    public float $urgent_threshold = self::DEFAULT_URGENT_THRESHOLD;
    public float $annual_inflation_rate = self::DEFAULT_INFLATION_RATE;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * Built-in fallback used when no row is configured for a division (and no
     * global default exists). Mirrors the historical Phase 2.5 hardcoded
     * algorithm so behavior stays identical for un-customized divisions.
     */
    public static function fallback(): self
    {
        $m = new self();
        $m->name = 'system-default';
        return $m;
    }

    /**
     * Returns weights normalized to sum to exactly 1.0. If the row was edited
     * to (0.6 / 0.4 / 0.4) it will scale down so risk still tops out at 100.
     *
     * @return array{condition: float, age: float, replace_by: float}
     */
    public function normalizedWeights(): array
    {
        $sum = $this->condition_weight + $this->age_weight + $this->replace_by_weight;
        if ($sum <= 0.0) {
            // Pathological row — fall back to defaults rather than divide by zero.
            return [
                'condition' => self::DEFAULT_CONDITION_WEIGHT,
                'age' => self::DEFAULT_AGE_WEIGHT,
                'replace_by' => self::DEFAULT_REPLACE_BY_WEIGHT,
            ];
        }
        return [
            'condition' => $this->condition_weight / $sum,
            'age' => $this->age_weight / $sum,
            'replace_by' => $this->replace_by_weight / $sum,
        ];
    }
}
