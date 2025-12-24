<?php

namespace App\Services\Estimate;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class BundleController
{
    private BundleService $bundles;
    private AccessGate $gate;

    public function __construct(BundleService $bundles, AccessGate $gate)
    {
        $this->bundles = $bundles;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        $this->assertViewAccess($user);

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $params = [
            'query' => $filters['query'] ?? null,
        ];

        if (isset($filters['active'])) {
            $params['active'] = $filters['active'];
        }

        return $this->bundles->list($params, $limit, $offset);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->assertViewAccess($user);

        return $this->bundles->show($id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function store(User $user, array $payload): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'bundles.create');

        $bundle = $this->bundles->create($payload);

        return $this->bundles->show($bundle->id) ?? $bundle->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $payload): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'bundles.update');

        $bundle = $this->bundles->update($id, $payload);

        return $bundle ? $this->bundles->show($id) : null;
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'bundles.delete');

        return $this->bundles->delete($id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchItemsForEstimate(User $user, int $bundleId): array
    {
        $this->assertEstimateAccess($user);

        $items = $this->bundles->fetchBundleItems($bundleId);

        if (empty($items)) {
            throw new InvalidArgumentException('Bundle not found or has no items.');
        }

        return $items;
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'bundles.view') || $this->gate->can($user, 'bundles.*') || $this->gate->can($user, 'estimates.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view bundles.');
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'bundles.*');
    }

    private function assertEstimateAccess(User $user): void
    {
        if ($this->gate->can($user, 'estimates.update') || $this->gate->can($user, 'estimates.*') || $this->gate->can($user, 'bundles.view') || $this->gate->can($user, 'bundles.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to fetch bundle items.');
    }
}
