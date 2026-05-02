<?php

namespace App\Services\PropertyManagement;

use App\Database\Connection;
use App\Models\TenantMaintenanceRequest;
use InvalidArgumentException;
use PDO;

/**
 * Data access for `tenant_maintenance_requests` — Phase 12 of
 * docs/woms-expansion-plan.md.
 *
 * Two access surfaces share this repo: the tenant portal (filters by
 * tenant_id, can create + cancel) and the staff queue (filters by status,
 * can triage / convert / decline). Authorization happens in the controller;
 * this class assumes its caller has already gated the request.
 */
class TenantMaintenanceRequestRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{tenant_id?: int, unit_id?: int, status?: string|array<int, string>} $filters
     * @return array<int, TenantMaintenanceRequest>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);

        $sql = 'SELECT * FROM tenant_maintenance_requests ' . $where
            . ' ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => TenantMaintenanceRequest::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array{tenant_id?: int, unit_id?: int, status?: string|array<int, string>} $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM tenant_maintenance_requests ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?TenantMaintenanceRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM tenant_maintenance_requests WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? TenantMaintenanceRequest::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TenantMaintenanceRequest
    {
        if (empty($data['tenant_id']) || empty($data['unit_id'])) {
            throw new InvalidArgumentException('tenant_id and unit_id are required.');
        }
        if (empty($data['title'])) {
            throw new InvalidArgumentException('title is required.');
        }

        $priority = $data['priority'] ?? 'normal';
        if (!in_array($priority, TenantMaintenanceRequest::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'Invalid priority. Allowed: ' . implode(', ', TenantMaintenanceRequest::PRIORITIES)
            );
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO tenant_maintenance_requests
                (tenant_id, unit_id, tenant_lease_id, category, priority, status, title, description)
             VALUES
                (:tenant_id, :unit_id, :tenant_lease_id, :category, :priority,
                 :status, :title, :description)'
        );
        $stmt->execute([
            'tenant_id' => (int) $data['tenant_id'],
            'unit_id' => (int) $data['unit_id'],
            'tenant_lease_id' => isset($data['tenant_lease_id']) ? (int) $data['tenant_lease_id'] : null,
            'category' => $data['category'] ?? null,
            'priority' => $priority,
            'status' => TenantMaintenanceRequest::STATUS_PENDING,
            'title' => (string) $data['title'],
            'description' => $data['description'] ?? null,
        ]);

        return $this->findById((int) $this->connection->pdo()->lastInsertId())
            ?? throw new \RuntimeException('Failed to load newly-created maintenance request.');
    }

    public function markTriaged(int $id, int $staffUserId): ?TenantMaintenanceRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE tenant_maintenance_requests
                SET status = :status, triaged_at = NOW(), triaged_by = :user_id
              WHERE id = :id AND status = :pending'
        );
        $stmt->execute([
            'status' => TenantMaintenanceRequest::STATUS_TRIAGED,
            'user_id' => $staffUserId,
            'id' => $id,
            'pending' => TenantMaintenanceRequest::STATUS_PENDING,
        ]);
        return $this->findById($id);
    }

    public function markConverted(int $id, int $workorderId, int $staffUserId): ?TenantMaintenanceRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE tenant_maintenance_requests
                SET status = :status, workorder_id = :wo_id,
                    converted_at = NOW(), converted_by = :user_id
              WHERE id = :id'
        );
        $stmt->execute([
            'status' => TenantMaintenanceRequest::STATUS_CONVERTED,
            'wo_id' => $workorderId,
            'user_id' => $staffUserId,
            'id' => $id,
        ]);
        return $this->findById($id);
    }

    public function markDeclined(int $id, string $reason, int $staffUserId): ?TenantMaintenanceRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE tenant_maintenance_requests
                SET status = :status, declined_reason = :reason,
                    triaged_at = COALESCE(triaged_at, NOW()),
                    triaged_by = COALESCE(triaged_by, :user_id)
              WHERE id = :id'
        );
        $stmt->execute([
            'status' => TenantMaintenanceRequest::STATUS_DECLINED,
            'reason' => substr($reason, 0, 255),
            'user_id' => $staffUserId,
            'id' => $id,
        ]);
        return $this->findById($id);
    }

    public function markCancelledByTenant(int $id, int $tenantId): ?TenantMaintenanceRequest
    {
        // Only cancellable while still pending — once staff has triaged it,
        // the workflow has left the tenant's hands.
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE tenant_maintenance_requests
                SET status = :status
              WHERE id = :id AND tenant_id = :tenant_id AND status = :pending'
        );
        $stmt->execute([
            'status' => TenantMaintenanceRequest::STATUS_CANCELLED,
            'id' => $id,
            'tenant_id' => $tenantId,
            'pending' => TenantMaintenanceRequest::STATUS_PENDING,
        ]);
        return $this->findById($id);
    }

    /**
     * @param array{tenant_id?: int, unit_id?: int, status?: string|array<int, string>} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $clauses = [];
        $params = [];

        if (!empty($filters['tenant_id'])) {
            $clauses[] = 'tenant_id = :tenant_id';
            $params['tenant_id'] = (int) $filters['tenant_id'];
        }
        if (!empty($filters['unit_id'])) {
            $clauses[] = 'unit_id = :unit_id';
            $params['unit_id'] = (int) $filters['unit_id'];
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            if (is_array($filters['status'])) {
                $i = 0;
                $placeholders = [];
                foreach ($filters['status'] as $statusValue) {
                    $key = 'status_' . $i++;
                    $placeholders[] = ':' . $key;
                    $params[$key] = (string) $statusValue;
                }
                $clauses[] = 'status IN (' . implode(',', $placeholders) . ')';
            } else {
                $clauses[] = 'status = :status';
                $params['status'] = (string) $filters['status'];
            }
        }

        $where = $clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses);
        return [$where, $params];
    }
}
