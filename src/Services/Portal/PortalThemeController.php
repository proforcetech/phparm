<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

/**
 * Phase 6.8 of docs/expansion-plan.md — HTTP facade for portal theming.
 * Admin CRUD, portal self-read, and public host resolution all route
 * through the same service; the controller just splits the surfaces.
 */
class PortalThemeController
{
    public function __construct(
        private readonly PortalThemeService $service,
    ) {
    }

    // ── Admin surface (users.create / users.view) ───────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function upsertForCompany(User $actor, int $companyId, array $body): array
    {
        return ['data' => $this->service->upsertTheme($actor, $companyId, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getForCompany(User $actor, int $companyId): array
    {
        return ['data' => $this->service->readForCompanyStaff($actor, $companyId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteForCompany(User $actor, int $companyId): array
    {
        $this->service->deleteTheme($actor, $companyId);
        return ['data' => ['deleted' => true, 'company_id' => $companyId]];
    }

    // ── Portal surface ──────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function readForPortal(User $user, PortalAccount $account): array
    {
        return ['data' => $this->service->readForPortal($user, $account)];
    }

    // ── Public surface (pre-auth) ───────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function publicResolveByHost(?string $host): array
    {
        return ['data' => $this->service->publicResolveOrDefault($host)];
    }
}
