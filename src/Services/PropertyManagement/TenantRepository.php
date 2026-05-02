<?php

namespace App\Services\PropertyManagement;

use App\Database\Connection;
use App\Models\Tenant;
use PDO;
use RuntimeException;

/**
 * Data access for the `tenants` table — Phase 12 of
 * docs/woms-expansion-plan.md.
 */
class TenantRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{status?: string, company_id?: int, search?: string} $filters
     * @return array<int, Tenant>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);

        $sql = 'SELECT * FROM tenants ' . $where . ' ORDER BY display_name LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => Tenant::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array{status?: string, company_id?: int, search?: string} $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM tenants ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?Tenant
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Tenant::fromRow($row) : null;
    }

    public function findByPortalUserId(int $userId): ?Tenant
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM tenants WHERE portal_user_id = :user_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Tenant::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Tenant
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO tenants
                (company_id, portal_user_id, entity_type, display_name,
                 primary_email, primary_phone, secondary_phone,
                 status, move_in_date, notes)
             VALUES
                (:company_id, :portal_user_id, :entity_type, :display_name,
                 :primary_email, :primary_phone, :secondary_phone,
                 :status, :move_in_date, :notes)'
        );
        $stmt->execute([
            'company_id' => isset($data['company_id']) ? (int) $data['company_id'] : null,
            'portal_user_id' => isset($data['portal_user_id']) ? (int) $data['portal_user_id'] : null,
            'entity_type' => $data['entity_type'] ?? 'individual',
            'display_name' => (string) $data['display_name'],
            'primary_email' => $data['primary_email'] ?? null,
            'primary_phone' => $data['primary_phone'] ?? null,
            'secondary_phone' => $data['secondary_phone'] ?? null,
            'status' => $data['status'] ?? 'active',
            'move_in_date' => $data['move_in_date'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $tenant = $this->findById($id);
        if ($tenant === null) {
            throw new RuntimeException('Failed to load newly created tenant');
        }
        return $tenant;
    }

    /**
     * Partial update — only keys present in $data are written.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Tenant
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("Tenant {$id} not found");
        }

        $fields = [];
        $params = ['id' => $id];

        $simple = [
            'entity_type', 'display_name', 'primary_email', 'primary_phone',
            'secondary_phone', 'status', 'move_in_date', 'notes',
        ];
        foreach ($simple as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('company_id', $data)) {
            $fields[] = 'company_id = :company_id';
            $params['company_id'] = $data['company_id'] === null ? null : (int) $data['company_id'];
        }
        if (array_key_exists('portal_user_id', $data)) {
            $fields[] = 'portal_user_id = :portal_user_id';
            $params['portal_user_id'] = $data['portal_user_id'] === null ? null : (int) $data['portal_user_id'];
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE tenants SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Tenant {$id} not found after update");
        }
        return $updated;
    }

    /**
     * @param array{status?: string, company_id?: int, search?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (isset($filters['company_id'])) {
            $where .= ' AND company_id = :company_id';
            $params['company_id'] = (int) $filters['company_id'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (display_name LIKE :search OR primary_email LIKE :search OR primary_phone LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        return [$where, $params];
    }
}
