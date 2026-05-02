<?php

namespace App\Services\PropertyManagement;

use App\Models\TenantLease;
use App\Models\Workorder;

/**
 * Resolves which party is billable for a property-mgmt workorder based on the
 * unit's currently-active lease — Phase 12 of docs/woms-expansion-plan.md.
 *
 * Used at WO creation/conversion time to snapshot the routing decision onto
 * `workorders.tenant_billable_party` so a later lease change does not
 * retroactively re-route prior invoices.
 *
 * Decision sources (in priority order):
 *   1. The lease's `billing_responsibility` column (landlord | tenant | split).
 *   2. For 'split': the lease's `maintenance_terms` JSON map keyed by
 *      WO category. Caller supplies the category (e.g., 'plumbing'); the
 *      map decides per category. Falls back to 'landlord' when the category
 *      is absent — landlord is responsible for anything not explicitly
 *      delegated, matching standard commercial-lease practice.
 *
 * Returns NULL when:
 *   - The WO has no `unit_id` (non-property-mgmt path; preserves legacy flow).
 *   - The unit has no active lease for the WO's date (vacant unit; landlord
 *     is the implicit billing party but we leave the field NULL so callers
 *     can distinguish "vacant" from "explicitly landlord").
 */
class TenantBillingResolver
{
    public function __construct(
        private readonly TenantLeaseRepository $leases,
    ) {
    }

    /**
     * Snapshot the billing party for a workorder from its unit's active lease.
     * Returns one of TenantLease::BILLING_* or NULL when no decision applies.
     */
    public function resolveForWorkorder(Workorder $workorder, ?string $category = null): ?string
    {
        $unitId = property_exists($workorder, 'unit_id') ? $workorder->unit_id : null;
        if ($unitId === null || $unitId === 0) {
            return null;
        }

        $referenceDate = $this->workorderReferenceDate($workorder);
        $lease = $this->leases->findActiveForUnit((int) $unitId, $referenceDate);
        if ($lease === null) {
            return null;
        }

        return $this->resolveForLease($lease, $category);
    }

    /**
     * Determine billing party from a lease + optional WO category.
     * Pure function over the lease + category — no DB access; safe to call
     * repeatedly during invoice generation.
     */
    public function resolveForLease(TenantLease $lease, ?string $category = null): string
    {
        if ($lease->billing_responsibility !== TenantLease::BILLING_SPLIT) {
            return $lease->billing_responsibility;
        }

        $terms = $lease->maintenance_terms ?? [];
        if ($category === null || !isset($terms[$category])) {
            // Default split fall-through: landlord is responsible for any
            // category not explicitly assigned to the tenant.
            return TenantLease::BILLING_LANDLORD;
        }

        $assigned = (string) $terms[$category];
        return in_array($assigned, [TenantLease::BILLING_LANDLORD, TenantLease::BILLING_TENANT], true)
            ? $assigned
            : TenantLease::BILLING_LANDLORD;
    }

    /**
     * Date used when picking the WO's active lease. Prefer the WO's
     * scheduled_for date when present so backdated requests resolve against
     * the lease that was active at the time of the work, not today's.
     */
    private function workorderReferenceDate(Workorder $workorder): string
    {
        foreach (['scheduled_for', 'created_at'] as $candidate) {
            if (!property_exists($workorder, $candidate)) {
                continue;
            }
            $value = $workorder->{$candidate};
            if (is_string($value) && $value !== '') {
                $date = substr($value, 0, 10);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                    return $date;
                }
            }
        }

        return date('Y-m-d');
    }
}
