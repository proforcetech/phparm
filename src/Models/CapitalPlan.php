<?php

namespace App\Models;

/**
 * Phase 9.3 — A scoped, multi-year capital-spend plan.
 *
 * scope_type/scope_id mirror the aging-report scopes:
 *   - 'company'     scope_id = customer_company.id
 *   - 'division'    scope_id = divisions.id
 *   - 'portfolio'   scope_id = NULL (whole org)
 *
 * base_year + horizon_years define the planning window. status is a coarse
 * lifecycle flag ('draft' | 'published' | 'archived') the planner uses to
 * lock a baseline before sharing.
 */
class CapitalPlan extends BaseModel
{
    public const SCOPE_COMPANY = 'company';
    public const SCOPE_DIVISION = 'division';
    public const SCOPE_PORTFOLIO = 'portfolio';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    public int $id = 0;
    public string $name = '';
    public string $scope_type = self::SCOPE_PORTFOLIO;
    public ?int $scope_id = null;
    public int $base_year = 0;
    public int $horizon_years = 5;
    public ?int $scoring_model_id = null;
    public string $status = self::STATUS_DRAFT;
    public ?string $notes = null;
    public ?int $created_by_user_id = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
