<?php

namespace App\Services\Customer;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class CustomerController
{
    private CustomerRepository $repository;
    private CustomerVehicleService $vehicleService;
    private AccessGate $gate;

    public function __construct(
        CustomerRepository $repository,
        AccessGate $gate,
        ?CustomerVehicleService $vehicleService = null
    ) {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->vehicleService = $vehicleService ?? new CustomerVehicleService($repository->connection());
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);

        $filters = [];
        foreach (['commercial', 'tax_exempt', 'has_balance'] as $flag) {
            if (isset($params[$flag])) {
                $filters[$flag] = (bool) $params[$flag];
            }
        }

        if (!empty($params['query'])) {
            $filters['query'] = trim((string) $params['query']);
        }

        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($customer) => $customer->toArray(), $this->repository->search($filters, $limit, $offset));
    }

    public function show(User $user, int $id): ?array
    {
        $this->assertViewAccess($user);

        return $this->repository->find($id)?->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'customers.create');

        $customer = $this->repository->create($data);

        return $customer->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'customers.update');

        $customer = $this->repository->update($id, $data);

        return $customer?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'customers.delete');

        return $this->repository->delete($id);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function attachVehicle(User $user, int $customerId, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'customers.update');

        return $this->vehicleService->attachVehicle($customerId, $data);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateVehicle(User $user, int $customerId, int $vehicleId, array $data): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'customers.update');

        return $this->vehicleService->updateVehicle($customerId, $vehicleId, $data);
    }

    public function deleteVehicle(User $user, int $customerId, int $vehicleId): bool
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'customers.update');

        return $this->vehicleService->deleteVehicle($customerId, $vehicleId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getVehicle(User $user, int $customerId, int $vehicleId): array
    {
        $this->assertViewAccess($user);

        $vehicles = $this->vehicleService->listVehicles($customerId);
        foreach ($vehicles as $vehicle) {
            if ($vehicle['id'] === $vehicleId) {
                return $vehicle;
            }
        }

        throw new \InvalidArgumentException('Vehicle not found for this customer.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listVehicles(User $user, int $customerId): array
    {
        $this->assertViewAccess($user);

        return $this->vehicleService->listVehicles($customerId);
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'customers.*');
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'customers.view') || $this->gate->can($user, 'customers.*')) {
            return;
        }

        if ($this->gate->can($user, 'portal.customers')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view customers.');
    }
}
