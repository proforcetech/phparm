<?php

namespace App\Services\Vehicle;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class VehicleMasterController
{
    private VehicleMasterRepository $repository;
    private AccessGate $gate;

    public function __construct(VehicleMasterRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertManageAccess($user);

        $filters = $this->extractFilters($params);
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 25;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($item) => $item->toArray(), $this->repository->search($filters, $limit, $offset));
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function autocomplete(User $user, array $params = []): array
    {
        $this->assertManageAccess($user);

        $term = isset($params['term']) ? trim((string) $params['term']) : '';
        if ($term === '') {
            throw new InvalidArgumentException('Provide a search term for autocomplete.');
        }

        $results = $this->repository->search(['term' => $term], $params['limit'] ?? 10);

        return array_map(static function ($vehicle) {
            $payload = $vehicle->toArray();
            $payload['label'] = sprintf(
                '%s %s %s %s',
                $payload['year'],
                $payload['make'],
                $payload['model'],
                $payload['engine']
            );

            return $payload;
        }, $results);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);

        $vehicle = $this->repository->create($data);

        return $vehicle->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->assertManageAccess($user);

        $vehicle = $this->repository->update($id, $data);

        return $vehicle?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);

        return $this->repository->delete($id);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params): array
    {
        $filters = [];
        foreach (['year', 'make', 'model', 'engine', 'transmission', 'drive', 'trim', 'term'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $filters[$field] = $params[$field];
            }
        }

        return $filters;
    }

    private function assertManageAccess(User $user): void
    {
        // Vehicle master data governs downstream dropdowns and normalization; restrict to manager/admin roles.
        $this->gate->assert($user, 'vehicles.*');
    }
}
