<?php

namespace App\Services\Vehicle;

use App\Models\User;
use App\Support\Auth\AccessGate;

class VehicleMasterUiService
{
    private VehicleMasterRepository $repository;
    private VehicleCascadeService $cascade;
    private AccessGate $gate;

    public function __construct(
        VehicleMasterRepository $repository,
        AccessGate $gate,
        ?VehicleCascadeService $cascade = null
    ) {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->cascade = $cascade ?? new VehicleCascadeService($repository);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function index(User $user, array $params = []): array
    {
        $this->gate->assert($user, 'vehicles.view');

        $filters = [];
        foreach (['year', 'make', 'model', 'engine', 'transmission', 'drive', 'trim', 'term'] as $field) {
            if (isset($params[$field]) && $params[$field] !== '') {
                $filters[$field] = $params[$field];
            }
        }

        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 25;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        $vehicles = array_map(static fn ($item) => $item->toArray(), $this->repository->search($filters, $limit, $offset));

        return [
            'filters' => $this->filterOptions($filters),
            'items' => $vehicles,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, array<int, string|int|null>>
     */
    private function filterOptions(array $filters): array
    {
        $options = [
            'years' => $this->repository->distinctValues('year'),
            'makes' => [],
            'models' => [],
            'engines' => [],
            'transmissions' => [],
            'drives' => [],
            'trims' => [],
        ];

        if (isset($filters['year'])) {
            $options['makes'] = $this->cascade->makes((int) $filters['year']);
        }

        if (isset($filters['year'], $filters['make'])) {
            $options['models'] = $this->cascade->models((int) $filters['year'], (string) $filters['make']);
        }

        if (isset($filters['year'], $filters['make'], $filters['model'])) {
            $options['engines'] = $this->cascade->engines((int) $filters['year'], (string) $filters['make'], (string) $filters['model']);
        }

        if (isset($filters['year'], $filters['make'], $filters['model'], $filters['engine'])) {
            $options['transmissions'] = $this->cascade->transmissions(
                (int) $filters['year'],
                (string) $filters['make'],
                (string) $filters['model'],
                (string) $filters['engine']
            );
        }

        if (isset($filters['year'], $filters['make'], $filters['model'], $filters['engine'], $filters['transmission'])) {
            $options['drives'] = $this->cascade->drives(
                (int) $filters['year'],
                (string) $filters['make'],
                (string) $filters['model'],
                (string) $filters['engine'],
                (string) $filters['transmission']
            );
        }

        if (isset($filters['year'], $filters['make'], $filters['model'], $filters['engine'], $filters['transmission'], $filters['drive'])) {
            $options['trims'] = $this->cascade->trims(
                (int) $filters['year'],
                (string) $filters['make'],
                (string) $filters['model'],
                (string) $filters['engine'],
                (string) $filters['transmission'],
                (string) $filters['drive']
            );
        }

        return $options;
    }
}
