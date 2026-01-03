<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class InventoryLookupController
{
    private InventoryLookupService $service;
    private AccessGate $gate;

    public function __construct(InventoryLookupService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    public function index(User $user, string $type, array $filters = []): array
    {
        $this->guard($user);

        return array_map(fn ($item) => $item->toArray(), $this->service->list($type, $filters));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(User $user, string $type, array $payload): array
    {
        $this->guard($user);

        return $this->service->create($type, $payload)->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(User $user, string $type, int $id, array $payload): array
    {
        $this->guard($user);

        $updated = $this->service->update($id, $type, $payload);
        if ($updated === null) {
            throw new InvalidArgumentException('Lookup not found');
        }

        return $updated->toArray();
    }

    public function destroy(User $user, string $type, int $id): void
    {
        $this->guard($user);

        $deleted = $this->service->delete($id, $type);
        if (!$deleted) {
            throw new InvalidArgumentException('Lookup not found');
        }
    }

    private function guard(User $user): void
    {
        if (!$this->gate->can($user, 'inventory.manage')) {
            throw new UnauthorizedException('Cannot manage inventory lookups');
        }
    }
}
