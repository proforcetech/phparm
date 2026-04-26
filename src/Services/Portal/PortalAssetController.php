<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

/**
 * Phase 6.4 of docs/expansion-plan.md — HTTP facade for the portal
 * site + site_asset read-only view.
 */
class PortalAssetController
{
    public function __construct(
        private readonly PortalAssetViewService $service,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listSites(User $user, PortalAccount $account): array
    {
        return ['data' => $this->service->listSites($account)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSite(User $user, PortalAccount $account, int $siteId): array
    {
        return ['data' => $this->service->getSite($account, $siteId)];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listAssetsAtSite(
        User $user,
        PortalAccount $account,
        int $siteId,
        array $query = [],
    ): array {
        $filters = [];
        if (isset($query['status'])) {
            $filters['status'] = (string) $query['status'];
        }
        if (isset($query['query']) && is_string($query['query'])) {
            $filters['query'] = $query['query'];
        }
        if (isset($query['limit'])) {
            $filters['limit'] = (int) $query['limit'];
        }
        if (isset($query['offset'])) {
            $filters['offset'] = (int) $query['offset'];
        }
        return $this->service->listAssetsAtSite($account, $siteId, $filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function getAsset(User $user, PortalAccount $account, int $assetId): array
    {
        return ['data' => $this->service->getAsset($account, $assetId)];
    }
}
