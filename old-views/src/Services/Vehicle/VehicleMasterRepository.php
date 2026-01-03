<?php

namespace App\Services\Vehicle;

use App\Database\Connection;
use App\Models\VehicleMaster;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class VehicleMasterRepository
{
    private Connection $connection;
    private VehicleMasterValidator $validator;

    /**
     * @var array<int, VehicleMaster>
     */
    private array $cache = [];

    /**
     * @var array<string, array<int, VehicleMaster>>
     */
    private array $searchCache = [];

    /**
     * @var array<string, array<int, string|int|null>>
     */
    private array $distinctCache = [];

    public function __construct(Connection $connection, ?VehicleMasterValidator $validator = null)
    {
        $this->connection = $connection;
        $this->validator = $validator ?? new VehicleMasterValidator();
    }

    public function find(int $id): ?VehicleMaster
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM vehicle_master WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $vehicle = new VehicleMaster($row);
        $this->cache[$id] = $vehicle;

        return $vehicle;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): VehicleMaster
    {
        $payload = $this->validator->validate($data);
        $this->assertUnique($payload);

        $sql = 'INSERT INTO vehicle_master (year, make, model, engine, transmission, drive, trim, created_at, updated_at) '
            . 'VALUES (:year, :make, :model, :engine, :transmission, :drive, :trim, :created_at, :updated_at)';

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'year' => $payload['year'],
            'make' => $payload['make'],
            'model' => $payload['model'],
            'engine' => $payload['engine'],
            'transmission' => $payload['transmission'],
            'drive' => $payload['drive'],
            'trim' => $payload['trim'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $vehicle = new VehicleMaster(array_merge($payload, ['id' => $id, 'created_at' => $now, 'updated_at' => $now]));
        $this->cache[$id] = $vehicle;
        $this->searchCache = [];

        return $vehicle;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?VehicleMaster
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $payload = $this->validator->validate(array_merge($existing->toArray(), $data));
        $this->assertUnique($payload, $id);

        $sql = 'UPDATE vehicle_master SET year = :year, make = :make, model = :model, engine = :engine, '
            . 'transmission = :transmission, drive = :drive, trim = :trim, updated_at = :updated_at WHERE id = :id';

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'year' => $payload['year'],
            'make' => $payload['make'],
            'model' => $payload['model'],
            'engine' => $payload['engine'],
            'transmission' => $payload['transmission'],
            'drive' => $payload['drive'],
            'trim' => $payload['trim'],
            'updated_at' => $now,
            'id' => $id,
        ]);

        $vehicle = new VehicleMaster(array_merge($payload, ['id' => $id, 'updated_at' => $now]));
        $this->cache[$id] = $vehicle;
        $this->searchCache = [];

        return $vehicle;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, VehicleMaster>
     */
    public function search(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $cacheKey = md5(json_encode([$filters, $limit, $offset]));
        if (isset($this->searchCache[$cacheKey])) {
            return $this->searchCache[$cacheKey];
        }

        $clauses = [];
        $bindings = [];

        foreach (['year', 'make', 'model', 'engine', 'transmission', 'drive', 'trim'] as $field) {
            if (!isset($filters[$field]) || $filters[$field] === '') {
                continue;
            }

            if ($field === 'year') {
                $clauses[] = 'year = :year';
                $bindings['year'] = (int) $filters['year'];
            } else {
                $clauses[] = "$field LIKE :$field";
                $bindings[$field] = $filters[$field] . '%';
            }
        }

        if (isset($filters['term']) && $filters['term'] !== '') {
            $clauses[] = '(make LIKE :term OR model LIKE :term OR engine LIKE :term OR transmission LIKE :term)';
            $bindings['term'] = $filters['term'] . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $sql = 'SELECT * FROM vehicle_master ' . $where . ' ORDER BY year DESC, make ASC, model ASC LIMIT :limit OFFSET :offset';
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
            $vehicle = new VehicleMaster($row);
            $results[] = $vehicle;
            $this->cache[$vehicle->id] = $vehicle;
        }

        $this->searchCache[$cacheKey] = $results;

        return $results;
    }

    /**
     * Fetch distinct values for the provided column, honoring the progressive filters for dropdown chains.
     *
     * @param array<string, mixed> $filters
     * @return array<int, string|int|null>
     */
    public function distinctValues(string $column, array $filters = []): array
    {
        $allowedColumns = ['year', 'make', 'model', 'engine', 'transmission', 'drive', 'trim'];
        if (!in_array($column, $allowedColumns, true)) {
            throw new InvalidArgumentException('Unsupported distinct column: ' . $column);
        }

        $cacheKey = md5(json_encode([$column, $filters]));
        if (isset($this->distinctCache[$cacheKey])) {
            return $this->distinctCache[$cacheKey];
        }

        $clauses = [];
        $bindings = [];
        foreach ($allowedColumns as $field) {
            if (!isset($filters[$field]) || $filters[$field] === '') {
                continue;
            }

            if ($field === 'year') {
                $clauses[] = 'year = :year';
                $bindings['year'] = (int) $filters['year'];
            } else {
                $clauses[] = "$field = :$field";
                $bindings[$field] = $filters[$field];
            }
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';
        $order = $column === 'year' ? 'DESC' : 'ASC';

        $sql = sprintf('SELECT DISTINCT %s FROM vehicle_master %s ORDER BY %s %s', $column, $where, $column, $order);
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        $values = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if ($value === null) {
                $values[] = null;
                continue;
            }

            $values[] = $column === 'year' ? (int) $value : (string) $value;
        }

        $this->distinctCache[$cacheKey] = $values;

        return $values;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM vehicle_master WHERE id = :id');
        $stmt->execute(['id' => $id]);

        unset($this->cache[$id]);
        $this->searchCache = [];

        return $stmt->rowCount() > 0;
    }

    /**
     * Locate an existing record matching the unique vehicle attributes.
     *
     * @param array<string, mixed> $payload
     */
    public function findByAttributes(array $payload): ?VehicleMaster
    {
        $sql = 'SELECT * FROM vehicle_master WHERE year = :year AND make = :make AND model = :model ' .
            'AND engine = :engine AND transmission = :transmission AND drive = :drive AND trim <=> :trim LIMIT 1';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'year' => $payload['year'],
            'make' => $payload['make'],
            'model' => $payload['model'],
            'engine' => $payload['engine'],
            'transmission' => $payload['transmission'],
            'drive' => $payload['drive'],
            'trim' => $payload['trim'] ?? null,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $vehicle = new VehicleMaster($row);
        $this->cache[$vehicle->id] = $vehicle;

        return $vehicle;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertUnique(array $payload, ?int $ignoreId = null): void
    {
        $clauses = [
            'year = :year',
            'make = :make',
            'model = :model',
            'engine = :engine',
            'transmission = :transmission',
            'drive = :drive',
            'trim <=> :trim',
        ];

        $bindings = [
            'year' => $payload['year'],
            'make' => $payload['make'],
            'model' => $payload['model'],
            'engine' => $payload['engine'],
            'transmission' => $payload['transmission'],
            'drive' => $payload['drive'],
            'trim' => $payload['trim'],
        ];

        $sql = 'SELECT id FROM vehicle_master WHERE ' . implode(' AND ', $clauses);
        if ($ignoreId !== null) {
            $sql .= ' AND id != :id';
            $bindings['id'] = $ignoreId;
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($bindings);

        if ($stmt->fetchColumn()) {
            throw new InvalidArgumentException('Duplicate vehicle master record detected.');
        }
    }
}
