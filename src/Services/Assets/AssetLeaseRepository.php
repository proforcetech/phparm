<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetLease;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for the `asset_leases` table — Phase 13 (M3) of
 * docs/woms-expansion-plan.md.
 *
 * Exposes a small set of windowed queries (`expiringWithin`,
 * `withMissingAlertAt`) that the daily expiry-alert worker uses to find
 * leases at the 90/60/30/0-day milestones without re-sending past alerts.
 */
class AssetLeaseRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{site_asset_id?: int, customer_id?: int, status?: string,
     *              expires_before?: string, expires_after?: string} $filters
     * @return array<int, AssetLease>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);

        $sql = 'SELECT * FROM asset_leases ' . $where
            . ' ORDER BY end_date ASC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => AssetLease::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);

        $stmt = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM asset_leases ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?AssetLease
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM asset_leases WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? AssetLease::fromRow($row) : null;
    }

    /**
     * Currently-active lease for an asset on a given date. Defensive against
     * overlaps (returns the most recent start) — schema does not enforce
     * one-active-lease-per-asset.
     */
    public function findActiveForAsset(int $siteAssetId, ?string $onDate = null): ?AssetLease
    {
        $onDate = $onDate ?? date('Y-m-d');

        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM asset_leases
              WHERE site_asset_id = :asset_id
                AND status = :status
                AND start_date <= :on_date
                AND end_date >= :on_date
              ORDER BY start_date DESC, id DESC
              LIMIT 1'
        );
        $stmt->execute([
            'asset_id' => $siteAssetId,
            'status' => AssetLease::STATUS_ACTIVE,
            'on_date' => $onDate,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? AssetLease::fromRow($row) : null;
    }

    /**
     * Active leases whose end_date falls in [today, today+$days]. Used by the
     * expiry alert worker to scan for upcoming milestones.
     *
     * @return array<int, AssetLease>
     */
    public function expiringWithin(int $days, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime($today . ' +' . max(0, $days) . ' days'));

        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM asset_leases
              WHERE status = :status
                AND end_date >= :today
                AND end_date <= :cutoff
              ORDER BY end_date ASC, id ASC'
        );
        $stmt->execute([
            'status' => AssetLease::STATUS_ACTIVE,
            'today' => $today,
            'cutoff' => $cutoff,
        ]);

        return array_map(
            static fn (array $row) => AssetLease::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Stamp the alert_<days>d_sent_at column. Caller is responsible for only
     * stamping when the column is currently NULL — we don't wrap that check
     * here because the worker batches by milestone and wants to know exactly
     * which rows were updated.
     */
    public function markAlertSent(int $id, int $milestoneDays, ?string $when = null): void
    {
        $column = $this->alertColumnFor($milestoneDays);
        $when = $when ?? date('Y-m-d H:i:s');

        $stmt = $this->connection->pdo()->prepare(
            "UPDATE asset_leases SET {$column} = :when WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'when' => $when]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AssetLease
    {
        $this->validateStatus($data['status'] ?? AssetLease::STATUS_ACTIVE);
        $this->validateSchedule($data['payment_schedule'] ?? AssetLease::SCHEDULE_MONTHLY);
        if (array_key_exists('end_of_lease_decision', $data) && $data['end_of_lease_decision'] !== null) {
            $this->validateDecision((string) $data['end_of_lease_decision']);
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO asset_leases
                (site_asset_id, customer_id, lessor_name, lessor_contact, lease_number,
                 start_date, end_date, monthly_payment_cents, payment_schedule,
                 mileage_cap, current_mileage, residual_value_cents, buyout_price_cents,
                 status, end_of_lease_decision, terms, notes, attachments)
             VALUES
                (:site_asset_id, :customer_id, :lessor_name, :lessor_contact, :lease_number,
                 :start_date, :end_date, :monthly_payment_cents, :payment_schedule,
                 :mileage_cap, :current_mileage, :residual_value_cents, :buyout_price_cents,
                 :status, :end_of_lease_decision, :terms, :notes, :attachments)'
        );
        $stmt->execute([
            'site_asset_id' => (int) $data['site_asset_id'],
            'customer_id' => isset($data['customer_id']) && $data['customer_id'] !== null
                ? (int) $data['customer_id'] : null,
            'lessor_name' => trim((string) ($data['lessor_name'] ?? '')),
            'lessor_contact' => $this->nullableString($data['lessor_contact'] ?? null),
            'lease_number' => $this->nullableString($data['lease_number'] ?? null),
            'start_date' => (string) $data['start_date'],
            'end_date' => (string) $data['end_date'],
            'monthly_payment_cents' => $this->nullableInt($data['monthly_payment_cents'] ?? null),
            'payment_schedule' => (string) ($data['payment_schedule'] ?? AssetLease::SCHEDULE_MONTHLY),
            'mileage_cap' => $this->nullableInt($data['mileage_cap'] ?? null),
            'current_mileage' => $this->nullableInt($data['current_mileage'] ?? null),
            'residual_value_cents' => $this->nullableInt($data['residual_value_cents'] ?? null),
            'buyout_price_cents' => $this->nullableInt($data['buyout_price_cents'] ?? null),
            'status' => (string) ($data['status'] ?? AssetLease::STATUS_ACTIVE),
            'end_of_lease_decision' => $this->nullableString($data['end_of_lease_decision'] ?? null),
            'terms' => $this->nullableString($data['terms'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
            'attachments' => isset($data['attachments']) && $data['attachments'] !== null
                ? json_encode($data['attachments']) : null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created lease');
        }
        return $row;
    }

    /**
     * Partial update — only keys present in $data are written.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): AssetLease
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("Asset lease {$id} not found");
        }

        if (array_key_exists('status', $data)) {
            $this->validateStatus((string) $data['status']);
        }
        if (array_key_exists('payment_schedule', $data)) {
            $this->validateSchedule((string) $data['payment_schedule']);
        }
        if (array_key_exists('end_of_lease_decision', $data) && $data['end_of_lease_decision'] !== null) {
            $this->validateDecision((string) $data['end_of_lease_decision']);
        }

        $fields = [];
        $params = ['id' => $id];

        $simple = [
            'lessor_name', 'lessor_contact', 'lease_number',
            'start_date', 'end_date', 'payment_schedule', 'status',
            'end_of_lease_decision', 'terms', 'notes',
        ];
        foreach ($simple as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }

        $intCols = [
            'customer_id', 'monthly_payment_cents', 'mileage_cap',
            'current_mileage', 'residual_value_cents', 'buyout_price_cents',
        ];
        foreach ($intCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (int) $data[$key];
            }
        }

        if (array_key_exists('attachments', $data)) {
            $fields[] = 'attachments = :attachments';
            $params['attachments'] = $data['attachments'] === null
                ? null
                : json_encode($data['attachments']);
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE asset_leases SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Asset lease {$id} not found after update");
        }
        return $updated;
    }

    /**
     * Capture an end-of-lease decision (renew / buyout / return / replace) and
     * advance the status to its pending companion. The actual downstream
     * workflow (renewal cycle, decommission, acquisition) is dispatched by the
     * controller — this just records the decision atomically.
     */
    public function recordDecision(int $id, string $decision, int $userId, ?string $when = null): AssetLease
    {
        $this->validateDecision($decision);
        $when = $when ?? date('Y-m-d H:i:s');

        $statusMap = [
            AssetLease::DECISION_RENEW => AssetLease::STATUS_PENDING_RENEWAL,
            AssetLease::DECISION_BUYOUT => AssetLease::STATUS_BUYOUT_PENDING,
            AssetLease::DECISION_RETURN => AssetLease::STATUS_PENDING_RENEWAL,
            AssetLease::DECISION_REPLACE => AssetLease::STATUS_PENDING_RENEWAL,
        ];

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE asset_leases
                SET end_of_lease_decision = :decision,
                    decision_made_at = :when,
                    decision_made_by = :user_id,
                    status = :status
              WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'decision' => $decision,
            'when' => $when,
            'user_id' => $userId,
            'status' => $statusMap[$decision],
        ]);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("Asset lease {$id} not found after decision");
        }
        return $row;
    }

    public function terminate(int $id, ?string $when = null): AssetLease
    {
        $when = $when ?? date('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE asset_leases SET status = :status WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'status' => AssetLease::STATUS_TERMINATED]);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("Asset lease {$id} not found after terminate");
        }
        return $row;
    }

    private function validateStatus(string $value): void
    {
        if (!in_array($value, AssetLease::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'Invalid lease status. Allowed: ' . implode(', ', AssetLease::ALLOWED_STATUSES)
            );
        }
    }

    private function validateSchedule(string $value): void
    {
        if (!in_array($value, AssetLease::ALLOWED_SCHEDULES, true)) {
            throw new InvalidArgumentException(
                'Invalid payment_schedule. Allowed: ' . implode(', ', AssetLease::ALLOWED_SCHEDULES)
            );
        }
    }

    private function validateDecision(string $value): void
    {
        if (!in_array($value, AssetLease::ALLOWED_DECISIONS, true)) {
            throw new InvalidArgumentException(
                'Invalid end_of_lease_decision. Allowed: ' . implode(', ', AssetLease::ALLOWED_DECISIONS)
            );
        }
    }

    private function alertColumnFor(int $milestoneDays): string
    {
        return match ($milestoneDays) {
            90 => 'alert_90d_sent_at',
            60 => 'alert_60d_sent_at',
            30 => 'alert_30d_sent_at',
            0 => 'alert_0d_sent_at',
            default => throw new InvalidArgumentException(
                'Invalid milestone days. Allowed: 90, 60, 30, 0'
            ),
        };
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

        if (isset($filters['site_asset_id'])) {
            $where .= ' AND site_asset_id = :site_asset_id';
            $params['site_asset_id'] = (int) $filters['site_asset_id'];
        }
        if (isset($filters['customer_id'])) {
            $where .= ' AND customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['expires_before'])) {
            $where .= ' AND end_date <= :expires_before';
            $params['expires_before'] = (string) $filters['expires_before'];
        }
        if (!empty($filters['expires_after'])) {
            $where .= ' AND end_date >= :expires_after';
            $params['expires_after'] = (string) $filters['expires_after'];
        }

        return [$where, $params];
    }
}
