<?php

namespace App\Services\PropertyManagement;

use App\Database\Connection;
use App\Models\TenantLease;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for the `tenant_leases` table — Phase 12 of
 * docs/woms-expansion-plan.md.
 */
class TenantLeaseRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{tenant_id?: int, unit_id?: int, status?: string, active_on?: string} $filters
     * @return array<int, TenantLease>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);

        $sql = 'SELECT * FROM tenant_leases ' . $where
            . ' ORDER BY start_date DESC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => TenantLease::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array{tenant_id?: int, unit_id?: int, status?: string, active_on?: string} $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM tenant_leases ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?TenantLease
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM tenant_leases WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? TenantLease::fromRow($row) : null;
    }

    /**
     * Single active lease for a unit on a given date. Returns the most recent
     * matching lease when overlaps exist (defensive — operational policy is
     * that overlaps shouldn't occur, but the schema does not enforce it).
     */
    public function findActiveForUnit(int $unitId, ?string $onDate = null): ?TenantLease
    {
        $onDate = $onDate ?? date('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM tenant_leases
              WHERE unit_id = :unit_id
                AND status = :status
                AND start_date <= :on_date
                AND (end_date IS NULL OR end_date >= :on_date)
              ORDER BY start_date DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            'unit_id' => $unitId,
            'status' => TenantLease::STATUS_ACTIVE,
            'on_date' => $onDate,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? TenantLease::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TenantLease
    {
        $this->validateBillingResponsibility($data['billing_responsibility'] ?? TenantLease::BILLING_LANDLORD);
        $this->validateStatus($data['status'] ?? TenantLease::STATUS_ACTIVE);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO tenant_leases
                (tenant_id, unit_id, start_date, end_date, monthly_rent,
                 deposit_amount, billing_responsibility, maintenance_terms,
                 status, terms, notes)
             VALUES
                (:tenant_id, :unit_id, :start_date, :end_date, :monthly_rent,
                 :deposit_amount, :billing_responsibility, :maintenance_terms,
                 :status, :terms, :notes)'
        );
        $stmt->execute([
            'tenant_id' => (int) $data['tenant_id'],
            'unit_id' => (int) $data['unit_id'],
            'start_date' => (string) $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'monthly_rent' => isset($data['monthly_rent']) ? (float) $data['monthly_rent'] : null,
            'deposit_amount' => isset($data['deposit_amount']) ? (float) $data['deposit_amount'] : null,
            'billing_responsibility' => (string) ($data['billing_responsibility'] ?? TenantLease::BILLING_LANDLORD),
            'maintenance_terms' => isset($data['maintenance_terms']) && $data['maintenance_terms'] !== null
                ? json_encode($data['maintenance_terms'])
                : null,
            'status' => (string) ($data['status'] ?? TenantLease::STATUS_ACTIVE),
            'terms' => $data['terms'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $lease = $this->findById($id);
        if ($lease === null) {
            throw new RuntimeException('Failed to load newly created lease');
        }
        return $lease;
    }

    /**
     * Partial update — only keys present in $data are written.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TenantLease
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("Tenant lease {$id} not found");
        }

        if (array_key_exists('billing_responsibility', $data)) {
            $this->validateBillingResponsibility((string) $data['billing_responsibility']);
        }
        if (array_key_exists('status', $data)) {
            $this->validateStatus((string) $data['status']);
        }

        $fields = [];
        $params = ['id' => $id];

        $simple = ['start_date', 'end_date', 'billing_responsibility', 'status', 'terms', 'notes'];
        foreach ($simple as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('monthly_rent', $data)) {
            $fields[] = 'monthly_rent = :monthly_rent';
            $params['monthly_rent'] = $data['monthly_rent'] === null ? null : (float) $data['monthly_rent'];
        }
        if (array_key_exists('deposit_amount', $data)) {
            $fields[] = 'deposit_amount = :deposit_amount';
            $params['deposit_amount'] = $data['deposit_amount'] === null ? null : (float) $data['deposit_amount'];
        }
        if (array_key_exists('maintenance_terms', $data)) {
            $fields[] = 'maintenance_terms = :maintenance_terms';
            $params['maintenance_terms'] = $data['maintenance_terms'] === null
                ? null
                : json_encode($data['maintenance_terms']);
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE tenant_leases SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Tenant lease {$id} not found after update");
        }
        return $updated;
    }

    private function validateBillingResponsibility(string $value): void
    {
        if (!in_array($value, TenantLease::ALLOWED_BILLING_PARTIES, true)) {
            throw new InvalidArgumentException(
                'Invalid billing_responsibility. Allowed: '
                . implode(', ', TenantLease::ALLOWED_BILLING_PARTIES)
            );
        }
    }

    private function validateStatus(string $value): void
    {
        if (!in_array($value, TenantLease::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid lease status. Allowed: ' . implode(', ', TenantLease::ALLOWED_STATUSES)
            );
        }
    }

    /**
     * @param array{tenant_id?: int, unit_id?: int, status?: string, active_on?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($filters['tenant_id'])) {
            $where .= ' AND tenant_id = :tenant_id';
            $params['tenant_id'] = (int) $filters['tenant_id'];
        }
        if (isset($filters['unit_id'])) {
            $where .= ' AND unit_id = :unit_id';
            $params['unit_id'] = (int) $filters['unit_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['active_on'])) {
            $where .= ' AND start_date <= :active_on AND (end_date IS NULL OR end_date >= :active_on)';
            $params['active_on'] = (string) $filters['active_on'];
        }

        return [$where, $params];
    }
}
