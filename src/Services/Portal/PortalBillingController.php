<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 6.4 of docs/expansion-plan.md — HTTP facade for the portal
 * billing surface: invoice listing, checkout initiation, and saved
 * payment method CRUD.
 */
class PortalBillingController
{
    public function __construct(
        private readonly PortalBillingService $service,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listInvoices(User $user, PortalAccount $account, array $query = []): array
    {
        $filters = [];
        $unpaidParam = $query['unpaid_only'] ?? null;
        if ($unpaidParam !== null) {
            $filters['unpaid_only'] = $unpaidParam === '1'
                || $unpaidParam === 'true'
                || $unpaidParam === true;
        }
        if (isset($query['limit'])) {
            $filters['limit'] = (int) $query['limit'];
        }
        return ['data' => $this->service->listInvoices($account, $filters)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getInvoice(User $user, PortalAccount $account, int $invoiceId): array
    {
        return ['data' => $this->service->getInvoice($account, $invoiceId)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function startCheckout(
        User $user,
        PortalAccount $account,
        int $invoiceId,
        array $body,
    ): array {
        $provider = trim((string) ($body['provider'] ?? ''));
        if ($provider === '') {
            throw new InvalidArgumentException('provider is required');
        }
        $options = [];
        foreach (['success_url', 'cancel_url'] as $key) {
            if (isset($body[$key]) && is_string($body[$key]) && $body[$key] !== '') {
                $options[$key] = $body[$key];
            }
        }
        return ['data' => $this->service->startCheckout(
            $user,
            $account,
            $invoiceId,
            $provider,
            $options,
        )];
    }

    /**
     * @return array<string, mixed>
     */
    public function listPaymentMethods(User $user, PortalAccount $account): array
    {
        return ['data' => $this->service->listPaymentMethods($account)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function savePaymentMethod(User $user, PortalAccount $account, array $body): array
    {
        return ['data' => $this->service->savePaymentMethod($user, $account, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function setDefaultMethod(User $user, PortalAccount $account, int $methodId): array
    {
        return ['data' => $this->service->setDefaultMethod($user, $account, $methodId)];
    }

    public function deletePaymentMethod(User $user, PortalAccount $account, int $methodId): void
    {
        $this->service->deletePaymentMethod($user, $account, $methodId);
    }
}
