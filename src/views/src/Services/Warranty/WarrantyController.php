<?php

namespace App\Services\Warranty;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class WarrantyController
{
    private WarrantyClaimService $service;
    private AccessGate $gate;

    public function __construct(WarrantyClaimService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * List warranty claims
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'warranty.view')) {
            throw new UnauthorizedException('Cannot view warranty claims');
        }

        $claims = $this->service->list($filters);
        return array_map(static fn ($c) => $c->toArray(), $claims);
    }

    /**
     * List warranty claims for the authenticated customer
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function customerIndex(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'portal.warranty')) {
            throw new UnauthorizedException('Cannot view customer warranty claims');
        }

        $customerId = $this->customerIdOrFail($user);
        $claims = $this->service->listForCustomer($customerId, $filters);

        return array_map(static fn ($c) => $c->toArray(), $claims);
    }

    /**
     * Get warranty claim
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'warranty.view')) {
            throw new UnauthorizedException('Cannot view warranty claims');
        }

        $claim = $this->service->findById($id);

        if ($claim === null) {
            throw new InvalidArgumentException('Warranty claim not found');
        }

        $data = $claim->toArray();
        $data['messages'] = array_map(static fn ($m) => $m->toArray(), $this->service->messages($claim->id));

        return $data;
    }

    /**
     * Get warranty claim scoped to the authenticated customer
     *
     * @return array<string, mixed>
     */
    public function customerShow(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'portal.warranty')) {
            throw new UnauthorizedException('Cannot view warranty claims');
        }

        $customerId = $this->customerIdOrFail($user);
        $claim = $this->service->findForCustomer($customerId, $id);

        if ($claim === null) {
            throw new InvalidArgumentException('Warranty claim not found');
        }

        $data = $claim->toArray();
        $data['messages'] = array_map(static fn ($m) => $m->toArray(), $this->service->messages($claim->id));

        return $data;
    }

    /**
     * Submit warranty claim (customer portal)
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'portal.warranty') && !$this->gate->can($user, 'warranty.create')) {
            throw new UnauthorizedException('Cannot submit warranty claims');
        }

        $customerId = $this->customerIdOrFail($user);
        $claim = $this->service->submit($data, $customerId, $user->id);
        return $claim->toArray();
    }

    /**
     * Update warranty claim status
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateStatus(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'warranty.update')) {
            throw new UnauthorizedException('Cannot update warranty claims');
        }

        if (!isset($data['status'])) {
            throw new InvalidArgumentException('status is required');
        }

        $claim = $this->service->updateStatus($id, (string) $data['status'], $user->id);

        if ($claim === null) {
            throw new InvalidArgumentException('Warranty claim not found');
        }

        return $claim->toArray();
    }

    /**
     * Reply to a warranty claim as the authenticated customer
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function reply(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'portal.warranty')) {
            throw new UnauthorizedException('Cannot reply to warranty claims');
        }

        if (empty($data['message'])) {
            throw new InvalidArgumentException('message is required');
        }

        $customerId = $this->customerIdOrFail($user);
        $claim = $this->service->replyAsCustomer($id, $customerId, (string) $data['message']);

        if ($claim === null) {
            throw new InvalidArgumentException('Warranty claim not found');
        }

        $data = $claim->toArray();
        $data['messages'] = array_map(static fn ($m) => $m->toArray(), $this->service->messages($claim->id));

        return $data;
    }

    private function customerIdOrFail(User $user): int
    {
        if ($user->customer_id === null) {
            throw new InvalidArgumentException('Customer context required for warranty claims');
        }

        return $user->customer_id;
    }
}
