<?php

namespace App\Services\Customer;

use App\Database\Connection;
use App\Models\Customer;
use DateTimeImmutable;
use PDO;

class CustomerRepository
{
    private Connection $connection;
    private CustomerValidator $validator;

    /**
     * @var array<int, Customer>
     */
    private array $cache = [];

    public function __construct(Connection $connection, ?CustomerValidator $validator = null)
    {
        $this->connection = $connection;
        $this->validator = $validator ?? new CustomerValidator();
    }

    public function connection(): Connection
    {
        return $this->connection;
    }

    public function find(int $id): ?Customer
    {
        if (isset($this->cache[$id])) {
            return $this->cache[$id];
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $customer = new Customer($row);
        $this->cache[$customer->id] = $customer;

        return $customer;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Customer
    {
        $payload = $this->validator->validate($data);

        $sql = 'INSERT INTO customers (name, email, phone, commercial, tax_exempt, notes, created_at, updated_at) '
            . 'VALUES (:name, :email, :phone, :commercial, :tax_exempt, :notes, :created_at, :updated_at)';

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'commercial' => $payload['commercial'] ? 1 : 0,
            'tax_exempt' => $payload['tax_exempt'] ? 1 : 0,
            'notes' => $payload['notes'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $customer = new Customer(array_merge($payload, ['id' => $id, 'created_at' => $now, 'updated_at' => $now]));
        $this->cache[$id] = $customer;

        return $customer;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ?Customer
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $payload = $this->validator->validate(array_merge($existing->toArray(), $data));

        $sql = 'UPDATE customers SET name = :name, email = :email, phone = :phone, commercial = :commercial, '
            . 'tax_exempt = :tax_exempt, notes = :notes, updated_at = :updated_at WHERE id = :id';

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'commercial' => $payload['commercial'] ? 1 : 0,
            'tax_exempt' => $payload['tax_exempt'] ? 1 : 0,
            'notes' => $payload['notes'],
            'updated_at' => $now,
            'id' => $id,
        ]);

        $customer = new Customer(array_merge($payload, ['id' => $id, 'updated_at' => $now, 'created_at' => $existing->created_at]));
        $this->cache[$id] = $customer;

        return $customer;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM customers WHERE id = :id');
        $stmt->execute(['id' => $id]);

        unset($this->cache[$id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, Customer>
     */
    public function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (isset($filters['commercial'])) {
            $clauses[] = 'commercial = :commercial';
            $bindings['commercial'] = $filters['commercial'] ? 1 : 0;
        }

        if (isset($filters['tax_exempt'])) {
            $clauses[] = 'tax_exempt = :tax_exempt';
            $bindings['tax_exempt'] = $filters['tax_exempt'] ? 1 : 0;
        }

        if (isset($filters['has_balance'])) {
            $clauses[] = $filters['has_balance'] ? 'balance_cents > 0' : 'balance_cents = 0';
        }

        if (!empty($filters['query'])) {
            $clauses[] = '(name LIKE :query OR email LIKE :query OR phone LIKE :query)';
            $bindings['query'] = '%' . $filters['query'] . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $sql = 'SELECT * FROM customers ' . $where . ' ORDER BY name ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $customer = new Customer($row);
            $results[] = $customer;
            $this->cache[$customer->id] = $customer;
        }

        return $results;
    }
}
