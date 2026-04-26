<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\Site;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 6.4 of docs/expansion-plan.md — portal-facing read-only view over
 * sites and installed site_assets.
 *
 * Scope layers applied for every entry point (belt + suspenders with
 * Middleware::portalAuth):
 *   1. portal_account.isUsable() — revoked account cannot read anything.
 *   2. site.company_id must match portal_account.company_id — cross-tenant
 *      rows are invisible even if the ID is guessable.
 *   3. PortalAuthService.assertSiteAccess honors allowed_site_ids — so a
 *      per-site-restricted portal user cannot enumerate sibling sites.
 *
 * Asset lookup always resolves the asset's site first and re-runs the
 * site scope check, so guessing an asset ID that belongs to a sibling
 * site returns the same "not found"-style response as an unknown ID.
 * Alarm/gate codes are stripped from site payloads — those require the
 * crm.sites.codes.view staff permission and never surface in the portal.
 */
class PortalAssetViewService
{
    public function __construct(
        private readonly SiteRepository $sites,
        private readonly SiteAssetRepository $assets,
        private readonly PortalAuthService $auth,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSites(PortalAccount $account): array
    {
        $this->assertUsable($account);
        $rows = $this->sites->listForCompany($account->company_id, true);
        $out = [];
        foreach ($rows as $site) {
            if (!$account->allowsSite($site->id)) {
                continue;
            }
            $out[] = $this->serializeSite($site);
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function getSite(PortalAccount $account, int $siteId): array
    {
        $site = $this->loadScopedSite($account, $siteId);
        return $this->serializeSite($site);
    }

    /**
     * @param array{status?: string, query?: string, limit?: int, offset?: int} $filters
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function listAssetsAtSite(PortalAccount $account, int $siteId, array $filters = []): array
    {
        $this->loadScopedSite($account, $siteId);
        $searchFilters = [
            'site_id' => $siteId,
            'limit' => max(1, min(500, (int) ($filters['limit'] ?? 100))),
            'offset' => max(0, (int) ($filters['offset'] ?? 0)),
        ];
        if (!empty($filters['status']) && is_string($filters['status'])) {
            $searchFilters['status'] = $filters['status'];
        }
        if (!empty($filters['query']) && is_string($filters['query'])) {
            $searchFilters['query'] = $filters['query'];
        }
        $result = $this->assets->search($searchFilters);
        return [
            'data' => array_map(
                fn(SiteAsset $a) => $this->serializeAsset($a),
                $result['data']
            ),
            'total' => $result['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getAsset(PortalAccount $account, int $assetId): array
    {
        $this->assertUsable($account);
        if ($assetId <= 0) {
            throw new InvalidArgumentException('asset id is required');
        }
        $asset = $this->assets->findById($assetId);
        if ($asset === null) {
            throw new InvalidArgumentException("asset {$assetId} not found");
        }
        // Re-resolve site scope — guessing an asset id from a sibling site
        // must look the same as "not found".
        $this->loadScopedSite($account, $asset->site_id);
        return $this->serializeAsset($asset);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function loadScopedSite(PortalAccount $account, int $siteId): Site
    {
        $this->assertUsable($account);
        if ($siteId <= 0) {
            throw new InvalidArgumentException('site id is required');
        }
        $site = $this->sites->findById($siteId);
        if ($site === null) {
            throw new InvalidArgumentException("site {$siteId} not found");
        }
        if ($site->company_id !== $account->company_id) {
            throw new UnauthorizedException('site does not belong to the portal account');
        }
        $this->auth->assertSiteAccess($account, $site->id);
        return $site;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * Strip staff-only fields. Alarm/gate codes and internal notes never
     * cross the portal boundary — they're gated behind crm.sites.codes.view
     * on the staff side.
     *
     * @return array<string, mixed>
     */
    private function serializeSite(Site $s): array
    {
        return [
            'id' => $s->id,
            'company_id' => $s->company_id,
            'name' => $s->name,
            'code' => $s->code,
            'is_primary' => $s->is_primary,
            'status' => $s->status,
            'street' => $s->street,
            'city' => $s->city,
            'state' => $s->state,
            'postal_code' => $s->postal_code,
            'country' => $s->country,
            'timezone' => $s->timezone,
            'phone' => $s->phone,
            'hours_json' => $s->hours_json,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAsset(SiteAsset $a): array
    {
        return [
            'id' => $a->id,
            'site_id' => $a->site_id,
            'asset_type_id' => $a->asset_type_id,
            'parent_asset_id' => $a->parent_asset_id,
            'name' => $a->name,
            'code' => $a->code,
            'status' => $a->status,
            'install_date' => $a->install_date,
            'manufacturer' => $a->manufacturer,
            'model_number' => $a->model_number,
            'serial_number' => $a->serial_number,
            'warranty_start' => $a->warranty_start,
            'warranty_end' => $a->warranty_end,
            'building' => $a->building,
            'floor' => $a->floor,
            'room' => $a->room,
            'rack' => $a->rack,
            'last_inspected_at' => $a->last_inspected_at,
            'replace_by_date' => $a->replace_by_date,
            'condition_score' => $a->condition_score,
        ];
    }
}
