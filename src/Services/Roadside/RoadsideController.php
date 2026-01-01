<?php

namespace App\Services\Roadside;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class RoadsideController
{
    private RoadsideService $service;
    private AccessGate $gate;

    public function __construct(RoadsideService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboard(User $user): array
    {
        if (!$this->gate->can($user, 'roadside.view')) {
            throw new UnauthorizedException('Cannot view roadside dashboard');
        }

        return $this->service->dashboardSummary();
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listRequests(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'roadside.view')) {
            throw new UnauthorizedException('Cannot view roadside requests');
        }

        return $this->service->listRequests($filters);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createRequest(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'roadside.create')) {
            throw new UnauthorizedException('Cannot create roadside requests');
        }

        return $this->service->createRequest($payload, $user->id);
    }
}
