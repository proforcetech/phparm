<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetAcquisition;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `asset_acquisitions` — Phase 13 (M4) of
 * docs/woms-expansion-plan.md.
 *
 * Persistence-only. State-transition rules and audit logging live in
 * AssetAcquisitionService. The repo's `applyTransition` is a write helper
 * for the service — it does NOT validate the transition itself.
 */
class AssetAcquisitionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?:int, site_id?:int, service_line_id?:int,
     *              asset_type_id?:int, status?:string, status_in?:array<int,string>,
     *              estimate_id?:int, install_workorder_id?:int} $filters
     * @return array<int, AssetAcquisition>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);

        $sql = 'SELECT * FROM asset_acquisitions ' . $where
            . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => AssetAcquisition::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM asset_acquisitions ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?AssetAcquisition
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM asset_acquisitions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? AssetAcquisition::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AssetAcquisition
    {
        $status = (string) ($data['status'] ?? AssetAcquisition::STATUS_DRAFT);
        $this->validateStatus($status);

        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new InvalidArgumentException('customer_id is required');
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('title is required');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO asset_acquisitions
                (customer_id, site_id, service_line_id, asset_type_id,
                 requested_by_user_id, requested_by_portal_user_id,
                 title, description, quantity, target_install_date, status,
                 last_state_changed_at, last_state_changed_by)
             VALUES
                (:customer_id, :site_id, :service_line_id, :asset_type_id,
                 :requested_by_user_id, :requested_by_portal_user_id,
                 :title, :description, :quantity, :target_install_date, :status,
                 CURRENT_TIMESTAMP, :last_state_changed_by)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'site_id' => $this->nullableInt($data['site_id'] ?? null),
            'service_line_id' => $this->nullableInt($data['service_line_id'] ?? null),
            'asset_type_id' => $this->nullableInt($data['asset_type_id'] ?? null),
            'requested_by_user_id' => $this->nullableInt($data['requested_by_user_id'] ?? null),
            'requested_by_portal_user_id' => $this->nullableInt($data['requested_by_portal_user_id'] ?? null),
            'title' => $title,
            'description' => $this->nullableString($data['description'] ?? null),
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'target_install_date' => $this->nullableString($data['target_install_date'] ?? null),
            'status' => $status,
            'last_state_changed_by' => $this->nullableInt($data['requested_by_user_id'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created acquisition');
        }
        return $row;
    }

    /**
     * Edit non-state fields (title/description/quantity/target_install_date,
     * vendor PO scaffold, install scheduling). Status changes go through
     * AssetAcquisitionService::transition() so the audit trail stays single-
     * sourced — this method REJECTS attempts to write `status` directly.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): AssetAcquisition
    {
        if (array_key_exists('status', $data)) {
            throw new InvalidArgumentException(
                'Status changes must go through AssetAcquisitionService::transition()'
            );
        }
        if ($this->findById($id) === null) {
            throw new RuntimeException("Acquisition {$id} not found");
        }

        $fields = [];
        $params = ['id' => $id];

        $strings = [
            'title', 'description', 'target_install_date',
            'vendor_name', 'vendor_po_number', 'vendor_po_issued_at',
            'install_scheduled_at', 'cancelled_reason',
            'customer_rejection_reason',
        ];
        foreach ($strings as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }

        $ints = [
            'site_id', 'service_line_id', 'asset_type_id', 'quantity',
            'estimate_id', 'vendor_po_total_cents',
            'install_workorder_id', 'target_site_asset_id',
        ];
        foreach ($ints as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE asset_acquisitions SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Acquisition {$id} not found after update");
        }
        return $updated;
    }

    /**
     * Atomic state advance + side-effect column writes. Caller (service) has
     * already validated the from→to transition and assembled the column
     * patches that go with it (timestamps, actor stamps, etc.).
     *
     * @param array<string, mixed> $sideEffects
     */
    public function applyTransition(int $id, string $toStatus, int $actorId, array $sideEffects = []): AssetAcquisition
    {
        $this->validateStatus($toStatus);

        $fields = ['status = :status', 'last_state_changed_at = CURRENT_TIMESTAMP', 'last_state_changed_by = :actor_id'];
        $params = ['id' => $id, 'status' => $toStatus, 'actor_id' => $actorId];

        foreach ($sideEffects as $key => $value) {
            $fields[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE asset_acquisitions SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("Acquisition {$id} not found after transition");
        }
        return $row;
    }

    private function validateStatus(string $value): void
    {
        if (!in_array($value, AssetAcquisition::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid acquisition status. Allowed: '
                . implode(', ', AssetAcquisition::ALLOWED_STATUSES)
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($filters['customer_id'])) {
            $where .= ' AND customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (isset($filters['site_id'])) {
            $where .= ' AND site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }
        if (isset($filters['service_line_id'])) {
            $where .= ' AND service_line_id = :service_line_id';
            $params['service_line_id'] = (int) $filters['service_line_id'];
        }
        if (isset($filters['asset_type_id'])) {
            $where .= ' AND asset_type_id = :asset_type_id';
            $params['asset_type_id'] = (int) $filters['asset_type_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['status_in']) && is_array($filters['status_in'])) {
            $placeholders = [];
            foreach (array_values($filters['status_in']) as $i => $status) {
                $placeholders[] = ":status_in_{$i}";
                $params["status_in_{$i}"] = (string) $status;
            }
            $where .= ' AND status IN (' . implode(', ', $placeholders) . ')';
        }
        if (isset($filters['estimate_id'])) {
            $where .= ' AND estimate_id = :estimate_id';
            $params['estimate_id'] = (int) $filters['estimate_id'];
        }
        if (isset($filters['install_workorder_id'])) {
            $where .= ' AND install_workorder_id = :install_workorder_id';
            $params['install_workorder_id'] = (int) $filters['install_workorder_id'];
        }

        return [$where, $params];
    }
}
