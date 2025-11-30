<?php

namespace App\Services\ServiceType;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class ServiceTypeController
{
    private ServiceTypeRepository $repository;
    private AccessGate $gate;

    public function __construct(ServiceTypeRepository $repository, AccessGate $gate)
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
        $this->assertViewAccess($user);

        $filters = [];
        if (isset($params['active'])) {
            $filters['active'] = (bool) $params['active'];
        }

        if (isset($params['query'])) {
            $filters['query'] = trim((string) $params['query']);
        }

        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($item) => $item->toArray(), $this->repository->list($filters, $limit, $offset));
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'service_types.create');

        $serviceType = $this->repository->create($data);

        return $serviceType->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'service_types.update');

        $serviceType = $this->repository->update($id, $data);

        return $serviceType?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'service_types.delete');

        return $this->repository->delete($id);
    }

    public function setActive(User $user, int $id, bool $active): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'service_types.update');

        $serviceType = $this->repository->setActive($id, $active);

        return $serviceType?->toArray();
    }

    /**
     * @param array<int, int> $orderedIds
     */
    public function reorder(User $user, array $orderedIds): void
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'service_types.update');

        $this->repository->updateDisplayOrder($orderedIds);
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'service_types.*');
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'service_types.view') || $this->gate->can($user, 'service_types.*')) {
            return;
        }

        if ($this->gate->can($user, 'portal.estimates')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view service types.');
    }
}
