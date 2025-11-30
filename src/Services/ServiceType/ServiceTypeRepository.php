<?php

namespace App\Services\ServiceType;

use App\Database\Connection;
use App\Models\ServiceType;
use InvalidArgumentException;
use PDO;
use Throwable;

class ServiceTypeRepository
{
    private Connection $connection;
    private ServiceTypeValidator $validator;

    /**
     * @var array<int, ServiceType>
     */
    private array $cache = [];

    /**
     * @var array<string, array<int, ServiceType>>
     */
    private array $listCache = [];

    public function __construct(Connection $connection, ?ServiceTypeValidator $validator = null)
    {
        $this->connection = $connection;
        $this->validator = $validator ?? new ServiceTypeValidator();
    }

    public function find(int $id): ?ServiceType
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM service_types WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $serviceType = $this->mapRow($row);
        $this->cache[$id] = $serviceType;

        return $serviceType;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ServiceType
    {
        $payload = $this->validator->validate($data);
        $this->assertUnique($payload);

        $sql = 'INSERT INTO service_types (name, alias, description, active, display_order) '
            . 'VALUES (:name, :alias, :description, :active, :display_order)';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $payload['name'],
            'alias' => $payload['alias'],
            'description' => $payload['description'],
            'active' => $payload['active'] ? 1 : 0,
            'display_order' => $payload['display_order'],
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $serviceType = new ServiceType(array_merge($payload, ['id' => $id]));
        $this->cache[$id] = $serviceType;
        $this->listCache = [];

        return $serviceType;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?ServiceType
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $payload = $this->validator->validate(array_merge($existing->toArray(), $data));
        $this->assertUnique($payload, $id);

        $sql = 'UPDATE service_types SET name = :name, alias = :alias, description = :description, '
            . 'active = :active, display_order = :display_order WHERE id = :id';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $payload['name'],
            'alias' => $payload['alias'],
            'description' => $payload['description'],
            'active' => $payload['active'] ? 1 : 0,
            'display_order' => $payload['display_order'],
            'id' => $id,
        ]);

        $serviceType = new ServiceType(array_merge($payload, ['id' => $id]));
        $this->cache[$id] = $serviceType;
        $this->listCache = [];

        return $serviceType;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, ServiceType>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $cacheKey = md5(json_encode([$filters, $limit, $offset]));
        if (isset($this->listCache[$cacheKey])) {
            return $this->listCache[$cacheKey];
        }

        $clauses = [];
        $bindings = [];

        if (isset($filters['active'])) {
            $clauses[] = 'active = :active';
            $bindings['active'] = $filters['active'] ? 1 : 0;
        }

        if (isset($filters['query']) && $filters['query'] !== '') {
            $clauses[] = '(name LIKE :query OR alias LIKE :query)';
            $bindings['query'] = $filters['query'] . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $sql = 'SELECT * FROM service_types ' . $where . ' ORDER BY display_order ASC, name ASC LIMIT :limit OFFSET :offset';
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $serviceType = $this->mapRow($row);
            $results[] = $serviceType;
            $this->cache[$serviceType->id] = $serviceType;
        }

        $this->listCache[$cacheKey] = $results;

        return $results;
    }

    public function setActive(int $id, bool $active): ?ServiceType
    {
        $serviceType = $this->find($id);
        if ($serviceType === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('UPDATE service_types SET active = :active WHERE id = :id');
        $stmt->execute([
            'active' => $active ? 1 : 0,
            'id' => $id,
        ]);

        $serviceType->active = $active;
        $this->cache[$id] = $serviceType;
        $this->listCache = [];

        return $serviceType;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM service_types WHERE id = :id');
        $stmt->execute(['id' => $id]);

        unset($this->cache[$id]);
        $this->listCache = [];

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<int, int> $orderedIds
     */
    public function updateDisplayOrder(array $orderedIds): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare('UPDATE service_types SET display_order = :display_order WHERE id = :id');
            foreach (array_values($orderedIds) as $index => $id) {
                $stmt->execute([
                    'display_order' => $index + 1,
                    'id' => $id,
                ]);
                unset($this->cache[$id]);
            }

            $pdo->commit();
            $this->listCache = [];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertUnique(array $payload, ?int $ignoreId = null): void
    {
        $clauses = ['name = :name'];
        $bindings = ['name' => $payload['name']];

        if ($payload['alias'] !== null) {
            $clauses[] = 'alias = :alias';
            $bindings['alias'] = $payload['alias'];
        }

        $sql = 'SELECT id, name, alias FROM service_types WHERE (' . implode(' OR ', $clauses) . ')';
        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $bindings['id'] = $ignoreId;
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($bindings);

        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($row['name'] === $payload['name']) {
                throw new InvalidArgumentException('A service type with this name already exists.');
            }

            if ($payload['alias'] !== null && $row['alias'] === $payload['alias']) {
                throw new InvalidArgumentException('A service type with this alias already exists.');
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRow(array $row): ServiceType
    {
        $row['active'] = (bool) $row['active'];
        $row['display_order'] = (int) $row['display_order'];

        return new ServiceType($row);
    }
}
