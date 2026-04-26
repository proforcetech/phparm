<?php

namespace App\Models;

/**
 * Phase 9.3 — A what-if variant of a CapitalPlan.
 *
 * Every plan auto-mints a Baseline scenario at create time; planners add
 * named variants ("Defer 12mo", "Accelerate urgent to year 1") on top.
 *
 * global_options is a JSON blob of cross-cutting transforms applied to every
 * non-overridden asset at compute time. Recognized keys:
 *   - defer_all_months (int)              shifts every replacement out by N months
 *   - accelerate_urgent_to_year (int)     pulls 'urgent'-category assets to this
 *                                         absolute year (clamped to base_year)
 *   - inflation_rate_override (float)     overrides scoring_model.annual_inflation
 *
 * is_baseline marks the auto-created untouchable variant so the UI can hide
 * destructive controls on it.
 */
class CapitalPlanScenario extends BaseModel
{
    public int $id = 0;
    public int $capital_plan_id = 0;
    public string $name = '';
    public bool $is_baseline = false;
    public ?array $global_options = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
