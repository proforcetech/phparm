<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\ServiceType;
use App\Models\User;
use App\Services\ServiceType\ServiceTypeController;
use App\Services\ServiceType\ServiceTypeRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use App\Support\Auth\RolePermissions;

class InMemoryServiceTypeRepository extends ServiceTypeRepository
{
    /**
     * @var array<int, ServiceType>
     */
    private array $items = [];

    public function __construct()
    {
        $this->items = [
            1 => new ServiceType([
                'id' => 1,
                'name' => 'Oil Change',
                'alias' => null,
                'description' => null,
                'active' => true,
                'display_order' => 1,
            ]),
            2 => new ServiceType([
                'id' => 2,
                'name' => 'Brake Service',
                'alias' => 'brakes',
                'description' => null,
                'active' => false,
                'display_order' => 2,
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, ServiceType>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $items = array_values($this->items);

        if (isset($filters['active'])) {
            $items = array_values(array_filter($items, static fn (ServiceType $item) => $item->active === (bool) $filters['active']));
        }

        if (isset($filters['query']) && $filters['query'] !== '') {
            $query = strtolower((string) $filters['query']);
            $items = array_values(array_filter($items, static function (ServiceType $item) use ($query): bool {
                return str_starts_with(strtolower($item->name), $query)
                    || ($item->alias !== null && str_starts_with(strtolower($item->alias), $query));
            }));
        }

        return array_slice($items, $offset, $limit);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ServiceType
    {
        $nextId = count($this->items) + 1;
        $serviceType = new ServiceType(array_merge([
            'id' => $nextId,
            'alias' => null,
            'description' => null,
            'active' => true,
            'display_order' => $nextId,
        ], $data));

        $this->items[$nextId] = $serviceType;

        return $serviceType;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?ServiceType
    {
        if (!isset($this->items[$id])) {
            return null;
        }

        $existing = $this->items[$id]->toArray();
        $this->items[$id] = new ServiceType(array_merge($existing, $data));

        return $this->items[$id];
    }

    public function delete(int $id): bool
    {
        if (!isset($this->items[$id])) {
            return false;
        }

        unset($this->items[$id]);

        return true;
    }

    public function setActive(int $id, bool $active): ?ServiceType
    {
        if (!isset($this->items[$id])) {
            return null;
        }

        $this->items[$id]->active = $active;

        return $this->items[$id];
    }

    /**
     * @param array<int, int> $orderedIds
     */
    public function updateDisplayOrder(array $orderedIds): void
    {
        foreach (array_values($orderedIds) as $index => $id) {
            if (isset($this->items[$id])) {
                $this->items[$id]->display_order = $index + 1;
            }
        }
    }
}

$config = loadAuthConfig();
$roles = new RolePermissions($config['roles']);
$gate = new AccessGate($roles);

$admin = new User(['id' => 1, 'name' => 'Admin', 'role' => 'admin']);
$manager = new User(['id' => 2, 'name' => 'Manager', 'role' => 'manager']);
$technician = new User(['id' => 3, 'name' => 'Tech', 'role' => 'technician']);
$customer = new User(['id' => 4, 'name' => 'Customer', 'role' => 'customer']);

$controller = new ServiceTypeController(new InMemoryServiceTypeRepository(), $gate);

$results = [];

$results[] = ['scenario' => 'admin can manage service types', 'passed' => $gate->can($admin, 'service_types.*')];
$results[] = ['scenario' => 'manager can manage service types', 'passed' => $gate->can($manager, 'service_types.*')];
$results[] = ['scenario' => 'technician can view but not manage service types', 'passed' => $gate->can($technician, 'service_types.view') && !$gate->can($technician, 'service_types.update')];

$portalList = $controller->index($customer, ['active' => true]);
$results[] = ['scenario' => 'customer portal can list active service types', 'passed' => count($portalList) === 1 && $portalList[0]['name'] === 'Oil Change'];

$managerCreate = $controller->store($manager, ['name' => 'Alignment', 'alias' => 'alignment', 'description' => null, 'active' => true, 'display_order' => 3]);
$results[] = ['scenario' => 'manager can create service type', 'passed' => $managerCreate['name'] === 'Alignment'];

$blocked = false;
try {
    $controller->store($technician, ['name' => 'Transmission Flush']);
} catch (UnauthorizedException $e) {
    $blocked = true;
}
$results[] = ['scenario' => 'technician cannot create service type', 'passed' => $blocked];

$failures = array_filter($results, static fn (array $row) => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All service type policy tests passed." . PHP_EOL;
