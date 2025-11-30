<?php

namespace App\Services\Vehicle;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class VehicleMasterController
{
    private VehicleMasterRepository $repository;
    private AccessGate $gate;
    private VehicleMasterImporter $importer;
    private VehicleCascadeService $cascade;

    public function __construct(
        VehicleMasterRepository $repository,
        AccessGate $gate,
        ?VehicleMasterImporter $importer = null,
        ?VehicleCascadeService $cascade = null
    )
    {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->importer = $importer ?? new VehicleMasterImporter($repository);
        $this->cascade = $cascade ?? new VehicleCascadeService($repository);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        $filters = $this->extractFilters($params);
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 25;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($item) => $item->toArray(), $this->repository->search($filters, $limit, $offset));
    }

    /**
     * @return array<int, int>
     */
    public function years(User $user): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->years();
    }

    /**
     * @return array<int, string>
     */
    public function makes(User $user, int $year): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->makes($year);
    }

    /**
     * @return array<int, string>
     */
    public function models(User $user, int $year, string $make): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->models($year, $make);
    }

    /**
     * @return array<int, string>
     */
    public function engines(User $user, int $year, string $make, string $model): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->engines($year, $make, $model);
    }

    /**
     * @return array<int, string>
     */
    public function transmissions(User $user, int $year, string $make, string $model, string $engine): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->transmissions($year, $make, $model, $engine);
    }

    /**
     * @return array<int, string>
     */
    public function drives(User $user, int $year, string $make, string $model, string $engine, string $transmission): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->drives($year, $make, $model, $engine, $transmission);
    }

    /**
     * @return array<int, string|null>
     */
    public function trims(
        User $user,
        int $year,
        string $make,
        string $model,
        string $engine,
        string $transmission,
        string $drive
    ): array {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

        return $this->cascade->trims($year, $make, $model, $engine, $transmission, $drive);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function autocomplete(User $user, array $params = []): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.view');

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
        $this->gate->assert($user, 'vehicles.create');

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
        $this->gate->assert($user, 'vehicles.update');

        $vehicle = $this->repository->update($id, $data);

        return $vehicle?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'vehicles.delete');

        return $this->repository->delete($id);
    }

    /**
     * @param array<string, int|string> $mapping
     * @return array<string, mixed>
     */
    public function importPreview(User $user, string $csv, array $mapping, int $limit = 20): array
    {
        $this->assertManageAccess($user);

        return $this->importer->preview($csv, $mapping, $limit);
    }

    /**
     * @param array<string, int|string> $mapping
     * @return array<string, mixed>
     */
    public function import(User $user, string $csv, array $mapping, bool $updateDuplicates = false): array
    {
        $this->assertManageAccess($user);

        return $this->importer->import($csv, $mapping, $updateDuplicates);
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
