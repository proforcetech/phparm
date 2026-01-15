<?php

namespace App\Services\Payments;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class OnsitePaymentController
{
    private OnsitePaymentService $service;
    private AccessGate $gate;

    public function __construct(OnsitePaymentService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCharge(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'payments.create')) {
            throw new UnauthorizedException('Cannot create on-site payments');
        }

        return $this->service->createCharge($payload, $user->id);
    }
}
