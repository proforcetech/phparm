<?php

namespace App\Models;

/**
 * Phase 6.1 of docs/expansion-plan.md — portal scope binding.
 *
 * See database/migrations/121_portal_accounts.sql for schema + rationale.
 * $allowed_site_ids === null means "all sites in the company"; an array
 * restricts the portal user to just those site IDs.
 */
class PortalAccount extends BaseModel
{
    public int $id = 0;
    public int $user_id = 0;
    public int $company_id = 0;
    /** @var array<int, int>|null */
    public ?array $allowed_site_ids = null;
    // Phase 2d (Decision C): role_tier is the named coarse permission
    // bucket; scope is the freeform overlay (see PortalPermissionService).
    // Default 'requester' matches the column DEFAULT in migration 179.
    public string $role_tier = 'requester';
    /** @var array<string, mixed>|null */
    public ?array $scope = null;
    public bool $is_active = true;
    public ?int $provisioned_by_user_id = null;
    public ?string $provisioned_at = null;
    public ?string $last_login_at = null;
    public ?string $revoked_at = null;
    public ?string $revoked_reason = null;
    public ?string $notes = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    public function isUsable(): bool
    {
        return $this->is_active && $this->revoked_at === null;
    }

    public function allowsSite(int $siteId): bool
    {
        if ($this->allowed_site_ids === null) {
            return true;
        }
        foreach ($this->allowed_site_ids as $allowed) {
            if ((int) $allowed === $siteId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Strict per-row site gate for transactional documents whose site is
     * resolved indirectly (invoices/workorders via site_assets, contracts
     * via contract_sites). Unscoped accounts (allowed_site_ids === null)
     * see everything in their company; scoped accounts must have at least
     * one matching site, AND a row with no resolvable site is excluded —
     * not silently passed through. See R-05 / AUD-067.
     *
     * For multi-site rows (contracts span multiple sites), pass every
     * resolved site id; ANY-match wins.
     *
     * @param array<int, int>|int|null $siteIds
     */
    public function allowsRowWithSite(array|int|null $siteIds): bool
    {
        if ($this->allowed_site_ids === null) {
            return true;
        }
        if ($siteIds === null) {
            return false;
        }
        $candidates = is_array($siteIds) ? $siteIds : [$siteIds];
        if ($candidates === []) {
            return false;
        }
        foreach ($candidates as $siteId) {
            if ($this->allowsSite((int) $siteId)) {
                return true;
            }
        }
        return false;
    }
}
