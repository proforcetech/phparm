<?php

namespace App\Services\SoftwareInventory;

use App\Database\Connection;
use App\Models\LicenseSeat;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `license_seats` (per-customer license pools) — Phase 14 /
 * M9 of docs/woms-expansion-plan.md.
 *
 * The seats_assigned counter MUST be mutated only via incrementAssigned /
 * decrementAssigned (called from SoftwareInventoryService inside a
 * transaction). Direct UPDATEs to that column from elsewhere break the
 * over-allocation invariant.
 */
class LicenseSeatRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int, software_asset_id?: int, status?: string,
     *              license_type?: string, expires_before?: string} $filters
     * @return array<int, LicenseSeat>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM license_seats ' . $where
            . ' ORDER BY expires_at ASC, id DESC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => LicenseSeat::fromRow($row),
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
            'SELECT COUNT(*) FROM license_seats ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?LicenseSeat
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM license_seats WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? LicenseSeat::fromRow($row) : null;
    }

    /**
     * Locking read used by SoftwareInventoryService::assign() to serialize
     * concurrent assignments against the same pool. Must be called inside a
     * transaction or the lock has no effect.
     */
    public function findByIdForUpdate(int $id): ?LicenseSeat
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM license_seats WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? LicenseSeat::fromRow($row) : null;
    }

    /**
     * Compliance view: pools whose seats_assigned > seats_owned. Surfaces
     * over-allocated rows that slipped past the guard (e.g. seats_owned was
     * lowered after assignments were made, or installed_software counts a
     * machine that has no license_assignment).
     *
     * @return array<int, LicenseSeat>
     */
    public function listOverAllocated(?int $customerId = null): array
    {
        $sql = 'SELECT * FROM license_seats WHERE seats_assigned > seats_owned';
        $params = [];
        if ($customerId !== null) {
            $sql .= ' AND customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }
        $sql .= ' ORDER BY (seats_assigned - seats_owned) DESC, id ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn (array $row) => LicenseSeat::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): LicenseSeat
    {
        $softwareAssetId = (int) ($data['software_asset_id'] ?? 0);
        $customerId = (int) ($data['customer_id'] ?? 0);
        if ($softwareAssetId <= 0 || $customerId <= 0) {
            throw new InvalidArgumentException('software_asset_id and customer_id are required');
        }
        $licenseType = (string) ($data['license_type'] ?? LicenseSeat::TYPE_SUBSCRIPTION);
        $this->validateType($licenseType);
        $status = (string) ($data['status'] ?? LicenseSeat::STATUS_ACTIVE);
        $this->validateStatus($status);

        $seatsOwned = max(0, (int) ($data['seats_owned'] ?? 0));

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO license_seats
                (software_asset_id, customer_id, license_type, license_key_ref,
                 vendor_name, purchase_order_ref, purchased_at, expires_at,
                 seats_owned, seats_assigned, cost_per_seat_cents, status, notes)
             VALUES
                (:software_asset_id, :customer_id, :license_type, :license_key_ref,
                 :vendor_name, :purchase_order_ref, :purchased_at, :expires_at,
                 :seats_owned, 0, :cost_per_seat_cents, :status, :notes)'
        );
        $stmt->execute([
            'software_asset_id' => $softwareAssetId,
            'customer_id' => $customerId,
            'license_type' => $licenseType,
            'license_key_ref' => $this->nullableString($data['license_key_ref'] ?? null),
            'vendor_name' => $this->nullableString($data['vendor_name'] ?? null),
            'purchase_order_ref' => $this->nullableString($data['purchase_order_ref'] ?? null),
            'purchased_at' => $this->nullableString($data['purchased_at'] ?? null),
            'expires_at' => $this->nullableString($data['expires_at'] ?? null),
            'seats_owned' => $seatsOwned,
            'cost_per_seat_cents' => $this->nullableInt($data['cost_per_seat_cents'] ?? null),
            'status' => $status,
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created license_seat');
        }
        return $row;
    }

    /**
     * Partial update. Refuses to overwrite seats_assigned (use the increment/
     * decrement helpers via SoftwareInventoryService instead). Refuses to
     * lower seats_owned below the current seats_assigned — caller must
     * unassign first.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): LicenseSeat
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("license_seat {$id} not found");
        }
        if (array_key_exists('seats_assigned', $data)) {
            throw new InvalidArgumentException(
                'seats_assigned must be mutated through SoftwareInventoryService::assign / unassign'
            );
        }
        if (array_key_exists('license_type', $data)) {
            $this->validateType((string) $data['license_type']);
        }
        if (array_key_exists('status', $data)) {
            $this->validateStatus((string) $data['status']);
        }
        if (array_key_exists('seats_owned', $data)) {
            $newOwned = (int) $data['seats_owned'];
            if ($newOwned < $existing->seats_assigned) {
                throw new InvalidArgumentException(
                    "Cannot lower seats_owned below current seats_assigned ({$existing->seats_assigned})"
                );
            }
            if ($newOwned < 0) {
                throw new InvalidArgumentException('seats_owned cannot be negative');
            }
        }

        $fields = [];
        $params = ['id' => $id];

        $stringCols = ['license_type', 'license_key_ref', 'vendor_name',
                       'purchase_order_ref', 'purchased_at', 'expires_at',
                       'status', 'notes'];
        foreach ($stringCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }

        $intCols = ['seats_owned', 'cost_per_seat_cents'];
        foreach ($intCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE license_seats SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("license_seat {$id} not found after update");
        }
        return $row;
    }

    public function incrementAssigned(int $id, int $delta = 1): void
    {
        if ($delta <= 0) {
            throw new InvalidArgumentException('delta must be positive');
        }
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE license_seats SET seats_assigned = seats_assigned + :delta WHERE id = :id'
        );
        $stmt->execute(['delta' => $delta, 'id' => $id]);
    }

    public function decrementAssigned(int $id, int $delta = 1): void
    {
        if ($delta <= 0) {
            throw new InvalidArgumentException('delta must be positive');
        }
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE license_seats
                SET seats_assigned = GREATEST(0, seats_assigned - :delta)
              WHERE id = :id'
        );
        $stmt->execute(['delta' => $delta, 'id' => $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM license_seats WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    private function validateType(string $value): void
    {
        if (!in_array($value, LicenseSeat::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                'Invalid license_type. Allowed: ' . implode(', ', LicenseSeat::ALLOWED_TYPES)
            );
        }
    }

    private function validateStatus(string $value): void
    {
        if (!in_array($value, LicenseSeat::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid status. Allowed: ' . implode(', ', LicenseSeat::ALLOWED_STATUSES)
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
        if (isset($filters['software_asset_id'])) {
            $where .= ' AND software_asset_id = :software_asset_id';
            $params['software_asset_id'] = (int) $filters['software_asset_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['license_type'])) {
            $where .= ' AND license_type = :license_type';
            $params['license_type'] = (string) $filters['license_type'];
        }
        if (!empty($filters['expires_before'])) {
            $where .= ' AND expires_at IS NOT NULL AND expires_at <= :expires_before';
            $params['expires_before'] = (string) $filters['expires_before'];
        }

        return [$where, $params];
    }
}
