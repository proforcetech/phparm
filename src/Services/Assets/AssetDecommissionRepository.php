<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetDecommission;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `asset_decommissions` — Phase 13 (M5) of
 * docs/woms-expansion-plan.md.
 *
 * Persistence-only. State-transition rules and audit logging live in
 * AssetDecommissionService. The repo's `applyTransition` is a write helper
 * for the service — it does NOT validate the transition itself.
 */
class AssetDecommissionRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?:int, site_asset_id?:int, status?:string,
     *              status_in?:array<int,string>, requires_wipe?:int,
     *              recovery_method?:string, requested_by_user_id?:int} $filters
     * @return array<int, AssetDecommission>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);

        $sql = 'SELECT * FROM asset_decommissions ' . $where
            . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => AssetDecommission::fromRow($row),
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
            'SELECT COUNT(*) FROM asset_decommissions ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?AssetDecommission
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM asset_decommissions WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? AssetDecommission::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AssetDecommission
    {
        $status = (string) ($data['status'] ?? AssetDecommission::STATUS_INITIATED);
        $this->validateStatus($status);

        $siteAssetId = (int) ($data['site_asset_id'] ?? 0);
        if ($siteAssetId <= 0) {
            throw new InvalidArgumentException('site_asset_id is required');
        }
        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new InvalidArgumentException('customer_id is required');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO asset_decommissions
                (site_asset_id, customer_id,
                 requested_by_user_id, requested_by_portal_user_id,
                 reason, notes, requires_wipe, recovery_method, status,
                 last_state_changed_at, last_state_changed_by)
             VALUES
                (:site_asset_id, :customer_id,
                 :requested_by_user_id, :requested_by_portal_user_id,
                 :reason, :notes, :requires_wipe, :recovery_method, :status,
                 CURRENT_TIMESTAMP, :last_state_changed_by)'
        );
        $stmt->execute([
            'site_asset_id' => $siteAssetId,
            'customer_id' => $customerId,
            'requested_by_user_id' => $this->nullableInt($data['requested_by_user_id'] ?? null),
            'requested_by_portal_user_id' => $this->nullableInt($data['requested_by_portal_user_id'] ?? null),
            'reason' => trim((string) ($data['reason'] ?? 'eol')) ?: 'eol',
            'notes' => $this->nullableString($data['notes'] ?? null),
            'requires_wipe' => !empty($data['requires_wipe']) ? 1 : 0,
            'recovery_method' => trim((string) ($data['recovery_method'] ?? 'none')) ?: 'none',
            'status' => $status,
            'last_state_changed_by' => $this->nullableInt($data['requested_by_user_id'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created decommission');
        }
        return $row;
    }

    /**
     * Edit non-state metadata fields. Status changes go through
     * AssetDecommissionService::transition() so the audit trail stays single-
     * sourced — this method REJECTS attempts to write `status` directly.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): AssetDecommission
    {
        if (array_key_exists('status', $data)) {
            throw new InvalidArgumentException(
                'Status changes must go through AssetDecommissionService::transition()'
            );
        }
        if ($this->findById($id) === null) {
            throw new RuntimeException("Decommission {$id} not found");
        }

        $fields = [];
        $params = ['id' => $id];

        $strings = [
            'reason', 'notes', 'recovery_method', 'wipe_certificate_url',
            'recovery_reference', 'cancelled_reason',
        ];
        foreach ($strings as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }

        $ints = ['recovery_value_cents'];
        foreach ($ints as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        if (array_key_exists('requires_wipe', $data)) {
            $fields[] = 'requires_wipe = :requires_wipe';
            $params['requires_wipe'] = !empty($data['requires_wipe']) ? 1 : 0;
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE asset_decommissions SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Decommission {$id} not found after update");
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
    public function applyTransition(int $id, string $toStatus, int $actorId, array $sideEffects = []): AssetDecommission
    {
        $this->validateStatus($toStatus);

        $fields = ['status = :status', 'last_state_changed_at = CURRENT_TIMESTAMP', 'last_state_changed_by = :actor_id'];
        $params = ['id' => $id, 'status' => $toStatus, 'actor_id' => $actorId];

        foreach ($sideEffects as $key => $value) {
            $fields[] = "{$key} = :{$key}";
            $params[$key] = $value;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE asset_decommissions SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("Decommission {$id} not found after transition");
        }
        return $row;
    }

    /**
     * Stamp the formal audit_logs row id captured during the `audited`
     * transition. Split out so the service can call it AFTER the audit row
     * has been written (whose id we don't know until then).
     */
    public function setAuditLogId(int $id, int $auditLogId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE asset_decommissions SET audit_log_id = :audit_log_id WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'audit_log_id' => $auditLogId]);
    }

    private function validateStatus(string $value): void
    {
        if (!in_array($value, AssetDecommission::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid decommission status. Allowed: '
                . implode(', ', AssetDecommission::ALLOWED_STATUSES)
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
        if (isset($filters['site_asset_id'])) {
            $where .= ' AND site_asset_id = :site_asset_id';
            $params['site_asset_id'] = (int) $filters['site_asset_id'];
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
        if (isset($filters['requires_wipe'])) {
            $where .= ' AND requires_wipe = :requires_wipe';
            $params['requires_wipe'] = !empty($filters['requires_wipe']) ? 1 : 0;
        }
        if (!empty($filters['recovery_method'])) {
            $where .= ' AND recovery_method = :recovery_method';
            $params['recovery_method'] = (string) $filters['recovery_method'];
        }
        if (isset($filters['requested_by_user_id'])) {
            $where .= ' AND requested_by_user_id = :requested_by_user_id';
            $params['requested_by_user_id'] = (int) $filters['requested_by_user_id'];
        }

        return [$where, $params];
    }
}
