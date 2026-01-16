<?php

namespace App\Services\Financial;

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDO;

class FinancialCategoryController
{
    private const ALLOWED_TYPES = ['asset', 'liability', 'income', 'expense', 'equity'];

    private Connection $connection;
    private AccessGate $gate;

    public function __construct(Connection $connection, AccessGate $gate)
    {
        $this->connection = $connection;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view financial categories');
        }

        $sql = 'SELECT id, name, type FROM financial_categories';
        $params = [];

        if (!empty($filters['type'])) {
            $type = $this->normalizeType((string) $filters['type']);
            if ($type === null) {
                throw new InvalidArgumentException('Invalid financial category type');
            }
            $sql .= ' WHERE type = :type';
            $params['type'] = $type;
        }

        $sql .= ' ORDER BY type ASC, name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function store(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.create')) {
            throw new UnauthorizedException('Cannot create financial categories');
        }

        $data = $this->validatePayload($payload);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO financial_categories (name, type) VALUES (:name, :type)'
        );
        $stmt->execute($data);

        $categoryId = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($categoryId) ?? [];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $payload): ?array
    {
        if (!$this->gate->can($user, 'financials.update')) {
            throw new UnauthorizedException('Cannot update financial categories');
        }

        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $data = $this->validatePayload($payload);
        $data['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE financial_categories SET name = :name, type = :type WHERE id = :id'
        );
        $stmt->execute($data);

        return $this->find($id);
    }

    public function destroy(User $user, int $id): bool
    {
        if (!$this->gate->can($user, 'financials.delete')) {
            throw new UnauthorizedException('Cannot delete financial categories');
        }

        $stmt = $this->connection->pdo()->prepare('DELETE FROM financial_categories WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    private function normalizeType(string $type): ?string
    {
        $normalized = strtolower(trim($type));

        return in_array($normalized, self::ALLOWED_TYPES, true) ? $normalized : null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validatePayload(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Financial category name is required');
        }
        if (mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Financial category name is too long');
        }

        $type = $this->normalizeType((string) ($payload['type'] ?? ''));
        if ($type === null) {
            throw new InvalidArgumentException('Invalid financial category type');
        }

        return [
            'name' => $name,
            'type' => $type,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function find(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, type FROM financial_categories WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
