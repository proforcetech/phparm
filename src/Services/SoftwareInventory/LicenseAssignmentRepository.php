<?php

namespace App\Services\SoftwareInventory;

use App\Database\Connection;
use App\Models\LicenseAssignment;
use PDO;
use RuntimeException;

/**
 * Data access for `license_assignments` — Phase 14 / M9 of
 * docs/woms-expansion-plan.md.
 *
 * Writes (insert / mark unassigned) MUST be paired with the matching
 * LicenseSeatRepository counter mutation inside a transaction. Use
 * SoftwareInventoryService::assign / unassign rather than calling these
 * methods directly.
 */
class LicenseAssignmentRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{license_seat_id?: int, assignee_user_id?: int,
     *              assignee_site_asset_id?: int, active_only?: bool,
     *              assignee_type?: string} $filters
     * @return array<int, LicenseAssignment>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(1000, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM license_assignments ' . $where
            . ' ORDER BY assigned_at DESC, id DESC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => LicenseAssignment::fromRow($row),
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
            'SELECT COUNT(*) FROM license_assignments ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?LicenseAssignment
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM license_assignments WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? LicenseAssignment::fromRow($row) : null;
    }

    /**
     * Active count for a pool. Used as a sanity check (and as the rebuild
     * source for the denormalized seats_assigned counter when reconciling).
     */
    public function countActiveForSeat(int $licenseSeatId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM license_assignments
              WHERE license_seat_id = :seat_id AND unassigned_at IS NULL'
        );
        $stmt->execute(['seat_id' => $licenseSeatId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Active assignment for a specific assignee against a pool, if any. Used
     * to reject duplicate assignments at the service layer.
     */
    public function findActiveForAssignee(
        int $licenseSeatId,
        string $assigneeType,
        int $assigneeId
    ): ?LicenseAssignment {
        $col = $assigneeType === LicenseAssignment::ASSIGNEE_USER
            ? 'assignee_user_id'
            : 'assignee_site_asset_id';

        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM license_assignments
              WHERE license_seat_id = :seat_id
                AND assignee_type = :type
                AND {$col} = :assignee_id
                AND unassigned_at IS NULL
              LIMIT 1"
        );
        $stmt->execute([
            'seat_id' => $licenseSeatId,
            'type' => $assigneeType,
            'assignee_id' => $assigneeId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? LicenseAssignment::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): LicenseAssignment
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO license_assignments
                (license_seat_id, assignee_type, assignee_user_id,
                 assignee_site_asset_id, assigned_at, assigned_by_user_id, notes)
             VALUES
                (:license_seat_id, :assignee_type, :assignee_user_id,
                 :assignee_site_asset_id, :assigned_at, :assigned_by_user_id, :notes)'
        );
        $stmt->execute([
            'license_seat_id' => (int) $data['license_seat_id'],
            'assignee_type' => (string) $data['assignee_type'],
            'assignee_user_id' => isset($data['assignee_user_id']) && $data['assignee_user_id'] !== null
                ? (int) $data['assignee_user_id'] : null,
            'assignee_site_asset_id' => isset($data['assignee_site_asset_id']) && $data['assignee_site_asset_id'] !== null
                ? (int) $data['assignee_site_asset_id'] : null,
            'assigned_at' => (string) ($data['assigned_at'] ?? date('Y-m-d H:i:s')),
            'assigned_by_user_id' => isset($data['assigned_by_user_id']) && $data['assigned_by_user_id'] !== null
                ? (int) $data['assigned_by_user_id'] : null,
            'notes' => isset($data['notes']) ? (string) $data['notes'] : null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created license_assignment');
        }
        return $row;
    }

    public function markUnassigned(
        int $id,
        ?int $byUserId = null,
        ?string $reason = null,
        ?string $when = null
    ): LicenseAssignment {
        $when = $when ?? date('Y-m-d H:i:s');

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE license_assignments
                SET unassigned_at = :when,
                    unassigned_by_user_id = :by_user,
                    unassign_reason = :reason
              WHERE id = :id AND unassigned_at IS NULL'
        );
        $stmt->execute([
            'id' => $id,
            'when' => $when,
            'by_user' => $byUserId,
            'reason' => $reason,
        ]);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("license_assignment {$id} not found after unassign");
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($filters['license_seat_id'])) {
            $where .= ' AND license_seat_id = :license_seat_id';
            $params['license_seat_id'] = (int) $filters['license_seat_id'];
        }
        if (isset($filters['assignee_user_id'])) {
            $where .= ' AND assignee_user_id = :assignee_user_id';
            $params['assignee_user_id'] = (int) $filters['assignee_user_id'];
        }
        if (isset($filters['assignee_site_asset_id'])) {
            $where .= ' AND assignee_site_asset_id = :assignee_site_asset_id';
            $params['assignee_site_asset_id'] = (int) $filters['assignee_site_asset_id'];
        }
        if (!empty($filters['assignee_type'])) {
            $where .= ' AND assignee_type = :assignee_type';
            $params['assignee_type'] = (string) $filters['assignee_type'];
        }
        if (!empty($filters['active_only'])) {
            $where .= ' AND unassigned_at IS NULL';
        }

        return [$where, $params];
    }
}
