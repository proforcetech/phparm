<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class CashDepositController
{
    private CashDepositService $service;
    private AccessGate $gate;

    public function __construct(CashDepositService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function undeposited(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view undeposited funds');
        }

        return $this->service->listUndepositedPayments($filters);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view cash deposits');
        }

        return $this->service->listDeposits($filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view cash deposits');
        }

        $deposit = $this->service->getDeposit($id);
        if ($deposit === null) {
            throw new InvalidArgumentException('Cash deposit not found');
        }

        return $deposit;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.create')) {
            throw new UnauthorizedException('Cannot create cash deposits');
        }

        return $this->service->createDeposit($payload, $user->id);
    }
}
