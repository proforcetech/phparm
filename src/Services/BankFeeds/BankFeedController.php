<?php

namespace App\Services\BankFeeds;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class BankFeedController
{
    private BankFeedService $service;
    private AccessGate $gate;

    public function __construct(BankFeedService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        if (!$this->gate->can($user, 'settings.view')) {
            throw new UnauthorizedException('Cannot view bank feed settings');
        }

        return $this->service->status();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function authorize(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'settings.update')) {
            throw new UnauthorizedException('Cannot authorize bank feeds');
        }

        return $this->service->authorize($payload, $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(User $user): array
    {
        if (!$this->gate->can($user, 'settings.update')) {
            throw new UnauthorizedException('Cannot sync bank feeds');
        }

        return $this->service->sync($user->id);
    }
}
