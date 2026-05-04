<?php

namespace App\Services\Procurement;

use App\Models\User;
use App\Models\Vendor;

/**
 * Phase 18 / S5 — thin HTTP facade over VendorService.
 */
class VendorController
{
    public function __construct(private readonly VendorService $service)
    {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function index(User $actor, array $filters = []): array
    {
        $result = $this->service->search($actor, $filters);
        return [
            'data' => array_map(static fn(Vendor $v) => $v->toArray(), $result['data']),
            'total' => $result['total'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, int $id): array
    {
        return ['data' => $this->service->get($actor, $id)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function create(User $actor, array $payload): array
    {
        return ['data' => $this->service->create($actor, $payload)->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(User $actor, int $id, array $payload): array
    {
        return ['data' => $this->service->update($actor, $id, $payload)->toArray()];
    }

    public function delete(User $actor, int $id): void
    {
        $this->service->delete($actor, $id);
    }
}
