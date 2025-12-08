<?php

namespace App\Services\Estimate;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class EstimateController
{
    private EstimateRepository $repository;
    private AccessGate $gate;

    public function __construct(EstimateRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);
        $filters = $this->extractFilters($params, $user);
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($estimate) => $estimate->toArray(), $this->repository->list($filters, $limit, $offset));
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $estimateId): array
    {
        $this->assertViewAccess($user);

        $estimate = $this->repository->find($estimateId);
        if ($estimate === null) {
            throw new InvalidArgumentException('Estimate not found');
        }

        if ($user->role === 'customer' && $user->customer_id !== null && $estimate->customer_id !== $user->customer_id) {
            throw new UnauthorizedException('Cannot view another customer\'s estimate.');
        }

        return $estimate->toArray();
    }

    public function approve(User $user, int $estimateId, ?string $reason = null): ?array
    {
        $this->assertManageAccess($user);

        $estimate = $this->repository->updateStatus($estimateId, 'approved', $user->id, $reason);

        return $estimate?->toArray();
    }

    public function reject(User $user, int $estimateId, ?string $reason = null): ?array
    {
        $this->assertManageAccess($user);

        $estimate = $this->repository->updateStatus($estimateId, 'rejected', $user->id, $reason);

        return $estimate?->toArray();
    }

    public function expire(User $user, int $estimateId, ?string $reason = null): ?array
    {
        $this->assertManageAccess($user);

        $estimate = $this->repository->updateStatus($estimateId, 'expired', $user->id, $reason);

        return $estimate?->toArray();
    }

    public function convertToInvoice(User $user, int $estimateId, string $issueDate, ?string $dueDate = null): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'invoices.create');

        $invoice = $this->repository->convertToInvoice($estimateId, $issueDate, $dueDate, $user->id);

        return $invoice?->toArray();
    }

    public function sendLink(User $user, int $estimateId, string $channel, string $recipient, string $link): array
    {
        $this->assertViewAccess($user);

        return $this->repository->logLinkDispatch($estimateId, $channel, $recipient, $link, $user->id);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params, ?User $user = null): array
    {
        $filters = [];
        foreach (['status', 'customer_id', 'vehicle_id', 'service_type_id', 'term', 'created_from', 'created_to'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $filters[$key] = $params[$key];
            }
        }

        if ($user !== null && $user->role === 'customer' && $user->customer_id !== null) {
            $filters['customer_id'] = $user->customer_id;
        }

        return $filters;
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'estimates.update');
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'estimates.view') || $this->gate->can($user, 'estimates.update')) {
            return;
        }

        if ($this->gate->can($user, 'portal.estimates')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view estimates.');
    }
}
