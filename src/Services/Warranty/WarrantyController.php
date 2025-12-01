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

        return $claim->toArray();
    }

    /**
     * Submit warranty claim (customer portal)
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        // Customers can submit their own claims
        $claim = $this->service->submit($data, $user->id);
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
}
