<?php

namespace App\Models;

/**
 * Phase 9.3 — A per-asset deviation within one scenario.
 *
 * Composed *on top of* the scenario's global_options, in this order:
 *   1) excluded → asset is dropped from the scenario entirely
 *   2) pin_to_year → asset replacement is forced to that absolute year
 *      (mutually exclusive with defer_months; pin wins if both set)
 *   3) defer_months → shifts the asset's replace_by_date by +/- N months
 *      from its baseline
 *   4) replacement_estimate_cents_override → forces a specific dollar amount
 *      instead of inheriting the asset row's stored estimate
 *
 * UNIQUE (scenario_id, site_asset_id) is enforced at the DB layer.
 */
class CapitalPlanScenarioOverride extends BaseModel
{
    public int $id = 0;
    public int $scenario_id = 0;
    public int $site_asset_id = 0;
    public ?int $defer_months = null;
    public ?int $pin_to_year = null;
    public ?int $replacement_estimate_cents_override = null;
    public bool $excluded = false;
    public ?string $notes = null;
    public ?string $created_at = null;
}
