<?php

namespace App\Services\Towing;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class TowingPricingController
{
    private TowingPricingService $pricing;
    private AccessGate $gate;

    public function __construct(TowingPricingService $pricing, AccessGate $gate)
    {
        $this->pricing = $pricing;
        $this->gate = $gate;
    }

    // ===== Service Classes =====

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listServiceClasses(User $user, array $filters = []): array
    {
        $this->assertViewAccess($user);
        $activeOnly = isset($filters['active']) ? (bool) $filters['active'] : false;
        return $this->pricing->listServiceClasses($activeOnly);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function showServiceClass(User $user, int $id): ?array
    {
        $this->assertViewAccess($user);
        $class = $this->pricing->findServiceClass($id);
        return $class ? $class->toArray() : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function storeServiceClass(User $user, array $payload): array
    {
        $this->assertManageAccess($user);
        $class = $this->pricing->createServiceClass($payload);
        return $class->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateServiceClass(User $user, int $id, array $payload): ?array
    {
        $this->assertManageAccess($user);
        $class = $this->pricing->updateServiceClass($id, $payload);
        return $class ? $class->toArray() : null;
    }

    public function destroyServiceClass(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        return $this->pricing->deleteServiceClass($id);
    }

    // ===== Service Types =====

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listServiceTypes(User $user, array $filters = []): array
    {
        $this->assertViewAccess($user);
        $activeOnly = isset($filters['active']) ? (bool) $filters['active'] : false;
        return $this->pricing->listServiceTypes($activeOnly);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function showServiceType(User $user, int $id): ?array
    {
        $this->assertViewAccess($user);
        $type = $this->pricing->findServiceType($id);
        return $type ? $type->toArray() : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function storeServiceType(User $user, array $payload): array
    {
        $this->assertManageAccess($user);
        $type = $this->pricing->createServiceType($payload);
        return $type->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updateServiceType(User $user, int $id, array $payload): ?array
    {
        $this->assertManageAccess($user);
        $type = $this->pricing->updateServiceType($id, $payload);
        return $type ? $type->toArray() : null;
    }

    public function destroyServiceType(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        return $this->pricing->deleteServiceType($id);
    }

    // ===== Price Matrix =====

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPriceMatrix(User $user, array $filters = []): array
    {
        $this->assertViewAccess($user);
        return $this->pricing->listPriceMatrix($filters);
    }

    /**
     * Get the full pricing matrix as a structured grid
     * @return array{classes: array, types: array, matrix: array}
     */
    public function getFullMatrix(User $user): array
    {
        $this->assertViewAccess($user);
        return $this->pricing->getFullMatrix();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function showPriceMatrix(User $user, int $id): ?array
    {
        $this->assertViewAccess($user);
        $matrix = $this->pricing->findPriceMatrix($id);
        return $matrix ? $matrix->toArray() : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function storePriceMatrix(User $user, array $payload): array
    {
        $this->assertManageAccess($user);
        $matrix = $this->pricing->createPriceMatrix($payload);
        return $matrix->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function updatePriceMatrix(User $user, int $id, array $payload): ?array
    {
        $this->assertManageAccess($user);
        $matrix = $this->pricing->updatePriceMatrix($id, $payload);
        return $matrix ? $matrix->toArray() : null;
    }

    /**
     * Upsert a price matrix entry (create or update)
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function upsertPriceMatrix(User $user, array $payload): array
    {
        $this->assertManageAccess($user);
        $matrix = $this->pricing->upsertPriceMatrix($payload);
        return $matrix->toArray();
    }

    /**
     * Bulk upsert multiple price matrix entries
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    public function bulkUpsertPriceMatrix(User $user, array $entries): array
    {
        $this->assertManageAccess($user);
        $results = $this->pricing->bulkUpsertPriceMatrix($entries);
        return array_map(fn ($m) => $m->toArray(), $results);
    }

    public function destroyPriceMatrix(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        return $this->pricing->deletePriceMatrix($id);
    }

    /**
     * Calculate price for a service
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function calculatePrice(User $user, int $classId, int $typeId, array $params = []): array
    {
        $this->assertViewAccess($user);
        return $this->pricing->calculatePrice($classId, $typeId, $params);
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'towing_pricing.view') ||
            $this->gate->can($user, 'towing_pricing.*') ||
            $this->gate->can($user, 'settings.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view towing pricing.');
    }

    private function assertManageAccess(User $user): void
    {
        if ($this->gate->can($user, 'towing_pricing.*') || $this->gate->can($user, 'settings.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to manage towing pricing.');
    }
}
