<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
class CashDrawerController
{
    private CashDrawerService $service;
    private AccessGate $gate;

    public function __construct(CashDrawerService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function active(User $user): ?array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view cash drawer sessions');
        }

        return $this->service->getActiveSession($user->id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function start(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.create')) {
            throw new UnauthorizedException('Cannot start cash drawer sessions');
        }

        return $this->service->startSession($user->id, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function close(User $user, int $sessionId, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.update')) {
            throw new UnauthorizedException('Cannot close cash drawer sessions');
        }

        return $this->service->closeSession($sessionId, $user->id, $payload);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function closeouts(User $user, array $filters): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view cash drawer reports');
        }

        return $this->service->listCloseouts($filters);
    }
}
