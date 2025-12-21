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

        $sql = 'INSERT INTO customers (
            first_name, last_name, business_name, email, phone,
            street, city, state, postal_code, country,
            billing_street, billing_city, billing_state, billing_postal_code, billing_country,
            is_commercial, tax_exempt, notes, external_reference, created_at, updated_at
        ) VALUES (
            :first_name, :last_name, :business_name, :email, :phone,
            :street, :city, :state, :postal_code, :country,
            :billing_street, :billing_city, :billing_state, :billing_postal_code, :billing_country,
            :is_commercial, :tax_exempt, :notes, :external_reference, :created_at, :updated_at
        )';

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'business_name' => $payload['business_name'] ?? null,
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'street' => $payload['street'] ?? null,
            'city' => $payload['city'] ?? null,
            'state' => $payload['state'] ?? null,
            'postal_code' => $payload['postal_code'] ?? null,
            'country' => $payload['country'] ?? null,
            'billing_street' => $payload['billing_street'] ?? null,
            'billing_city' => $payload['billing_city'] ?? null,
            'billing_state' => $payload['billing_state'] ?? null,
            'billing_postal_code' => $payload['billing_postal_code'] ?? null,
            'billing_country' => $payload['billing_country'] ?? null,
            'is_commercial' => isset($payload['is_commercial']) ? ($payload['is_commercial'] ? 1 : 0) : 0,
            'tax_exempt' => isset($payload['tax_exempt']) ? ($payload['tax_exempt'] ? 1 : 0) : 0,
            'notes' => $payload['notes'] ?? null,
            'external_reference' => $payload['external_reference'] ?? null,
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

        $sql = 'UPDATE customers SET
            first_name = :first_name,
            last_name = :last_name,
            business_name = :business_name,
            email = :email,
            phone = :phone,
            street = :street,
            city = :city,
            state = :state,
            postal_code = :postal_code,
            country = :country,
            billing_street = :billing_street,
            billing_city = :billing_city,
            billing_state = :billing_state,
            billing_postal_code = :billing_postal_code,
            billing_country = :billing_country,
            is_commercial = :is_commercial,
            tax_exempt = :tax_exempt,
            notes = :notes,
            external_reference = :external_reference,
            updated_at = :updated_at
        WHERE id = :id';

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'first_name' => $payload['first_name'],
            'last_name' => $payload['last_name'],
            'business_name' => $payload['business_name'] ?? null,
            'email' => $payload['email'],
            'phone' => $payload['phone'],
            'street' => $payload['street'] ?? null,
            'city' => $payload['city'] ?? null,
            'state' => $payload['state'] ?? null,
            'postal_code' => $payload['postal_code'] ?? null,
            'country' => $payload['country'] ?? null,
            'billing_street' => $payload['billing_street'] ?? null,
            'billing_city' => $payload['billing_city'] ?? null,
            'billing_state' => $payload['billing_state'] ?? null,
            'billing_postal_code' => $payload['billing_postal_code'] ?? null,
            'billing_country' => $payload['billing_country'] ?? null,
            'is_commercial' => isset($payload['is_commercial']) ? ($payload['is_commercial'] ? 1 : 0) : 0,
            'tax_exempt' => isset($payload['tax_exempt']) ? ($payload['tax_exempt'] ? 1 : 0) : 0,
            'notes' => $payload['notes'] ?? null,
            'external_reference' => $payload['external_reference'] ?? null,
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
            $clauses[] = 'is_commercial = :commercial';
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
            $clauses[] = '(id = :exact_id OR first_name LIKE :query OR last_name LIKE :query OR business_name LIKE :query OR email LIKE :query OR phone LIKE :query)';
            $bindings['exact_id'] = is_numeric($filters['query']) ? (int) $filters['query'] : 0;
            $bindings['query'] = '%' . $filters['query'] . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $sql = 'SELECT * FROM customers ' . $where . ' ORDER BY last_name ASC, first_name ASC LIMIT :limit OFFSET :offset';
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
