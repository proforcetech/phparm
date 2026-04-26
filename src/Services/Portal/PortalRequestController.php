<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

/**
 * Phase 6.2 of docs/expansion-plan.md — HTTP facade for the portal
 * request wizard. Route handlers stay trivially thin; the service owns
 * invariants and authorization.
 */
class PortalRequestController
{
    public function __construct(
        private readonly PortalRequestWizardService $wizard,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listRequestTypes(User $user, PortalAccount $account): array
    {
        return ['data' => $this->wizard->listRequestTypes($account)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listCategoriesForType(
        User $user,
        PortalAccount $account,
        int $rootCategoryId,
    ): array {
        return [
            'data' => $this->wizard->listCategoriesForType($account, $rootCategoryId),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function submit(User $user, PortalAccount $account, array $body): array
    {
        $ticket = $this->wizard->submitRequest($user, $account, $body);
        return ['data' => $ticket->toArray()];
    }
}
