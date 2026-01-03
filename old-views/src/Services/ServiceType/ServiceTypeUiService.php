<?php

namespace App\Services\ServiceType;

use App\Models\User;
use App\Support\Auth\AccessGate;

class ServiceTypeUiService
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
     * @return array<string, mixed>
     */
    public function index(User $user, array $params = []): array
    {
        $this->gate->assert($user, 'service_types.view');

        $filters = [];
        if (isset($params['active'])) {
            $filters['active'] = (bool) $params['active'];
        }
        if (!empty($params['query'])) {
            $filters['query'] = trim((string) $params['query']);
        }

        $items = $this->repository->list($filters, $params['limit'] ?? 100, $params['offset'] ?? 0);

        return [
            'items' => array_map(static fn ($item) => $item->toArray(), $items),
            'filters' => [
                'active' => $filters['active'] ?? null,
                'query' => $filters['query'] ?? null,
            ],
            'ordering' => $this->repository->displayOrder(),
        ];
    }

    /**
     * @param array<int, int> $orderedIds
     */
    public function reorder(User $user, array $orderedIds): void
    {
        $this->gate->assert($user, 'service_types.update');
        $this->repository->updateDisplayOrder($orderedIds, $user->id);
    }
}
