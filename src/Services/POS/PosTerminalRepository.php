<?php

namespace App\Services\POS;

use App\Database\Connection;
use App\Models\PosTerminal;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `pos_terminals` — Phase 16 / S2 of
 * docs/woms-expansion-plan.md.
 *
 * Owns the registry of POS devices: terminal_code (public-facing),
 * shared_secret (HMAC), staleness thresholds, plus the hot-path denorm of
 * `last_seen_at` / `last_status` / `last_alert_ticket_id`. Heartbeat rows
 * themselves live in `pos_heartbeats` via `PosHeartbeatRepository`; the
 * recordHeartbeat() method here updates the denorm in lockstep with the
 * heartbeat insert, called from inside one transaction at the service layer.
 *
 * Coordinated with PosTerminalService for config writes so the
 * programming_logs ledger stays in sync.
 */
class PosTerminalRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int, site_id?: int, status?: string,
     *              search?: string, stale_after?: string} $filters
     * @return array<int, PosTerminal>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM pos_terminals ' . $where
            . ' ORDER BY terminal_code ASC, id ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => PosTerminal::fromRow($row),
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
            'SELECT COUNT(*) FROM pos_terminals ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?PosTerminal
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM pos_terminals WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? PosTerminal::fromRow($row) : null;
    }

    public function findByCode(int $customerId, string $terminalCode): ?PosTerminal
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM pos_terminals
              WHERE customer_id = :c AND terminal_code = :code LIMIT 1'
        );
        $stmt->execute(['c' => $customerId, 'code' => $terminalCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? PosTerminal::fromRow($row) : null;
    }

    /**
     * Look up a terminal by code alone — used by the heartbeat webhook
     * receiver where the customer scope is implied by the terminal_code's
     * uniqueness across the (customer_id, terminal_code) namespace. Returns
     * NULL when the code matches more than one customer (caller must then
     * route by some other context — currently a no-op since we use a single
     * code namespace per deployment).
     */
    public function findByCodeAcrossCustomers(string $terminalCode): ?PosTerminal
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM pos_terminals WHERE terminal_code = :code LIMIT 2'
        );
        $stmt->execute(['code' => $terminalCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== 1) {
            return null;
        }
        return PosTerminal::fromRow($rows[0]);
    }

    /**
     * Active terminals whose last_seen_at is older than their per-terminal
     * stale_after_seconds threshold (or never seen). Used by the cron
     * sweeper.
     *
     * @return array<int, PosTerminal>
     */
    public function listStale(?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare(
            "SELECT * FROM pos_terminals
              WHERE status = :active
                AND (
                    last_seen_at IS NULL
                    OR TIMESTAMPDIFF(SECOND, last_seen_at, :now) >= stale_after_seconds
                )
              ORDER BY id ASC"
        );
        $stmt->execute([
            'active' => PosTerminal::STATUS_ACTIVE,
            'now' => $now,
        ]);
        return array_map(
            static fn (array $row) => PosTerminal::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): PosTerminal
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $siteId = (int) ($data['site_id'] ?? 0);
        $terminalCode = trim((string) ($data['terminal_code'] ?? ''));
        $sharedSecret = (string) ($data['shared_secret'] ?? '');
        if ($customerId <= 0 || $siteId <= 0 || $terminalCode === '' || $sharedSecret === '') {
            throw new InvalidArgumentException(
                'customer_id, site_id, terminal_code, and shared_secret are required'
            );
        }
        $status = (string) ($data['status'] ?? PosTerminal::STATUS_ACTIVE);
        if (!in_array($status, PosTerminal::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status '{$status}'");
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO pos_terminals
                (customer_id, site_id, site_asset_id, terminal_code, name,
                 vendor, model, serial_number, shared_secret,
                 heartbeat_interval_seconds, stale_after_seconds, status, notes)
             VALUES
                (:customer_id, :site_id, :site_asset_id, :terminal_code, :name,
                 :vendor, :model, :serial_number, :shared_secret,
                 :heartbeat_interval_seconds, :stale_after_seconds, :status, :notes)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'site_id' => $siteId,
            'site_asset_id' => $this->nullableInt($data['site_asset_id'] ?? null),
            'terminal_code' => $terminalCode,
            'name' => $this->nullableString($data['name'] ?? null),
            'vendor' => $this->nullableString($data['vendor'] ?? null),
            'model' => $this->nullableString($data['model'] ?? null),
            'serial_number' => $this->nullableString($data['serial_number'] ?? null),
            'shared_secret' => $sharedSecret,
            'heartbeat_interval_seconds' => max(1, (int) ($data['heartbeat_interval_seconds'] ?? 60)),
            'stale_after_seconds' => max(1, (int) ($data['stale_after_seconds'] ?? 300)),
            'status' => $status,
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created pos_terminal');
        }
        return $row;
    }

    /**
     * Partial update — does NOT touch the denorm (last_seen_at, last_status,
     * last_alert_ticket_id) or shared_secret. Use rotateSecret() / the
     * heartbeat path for those.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): PosTerminal
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("pos_terminal {$id} not found");
        }
        $fields = [];
        $params = ['id' => $id];

        $stringCols = ['name', 'vendor', 'model', 'serial_number', 'notes'];
        foreach ($stringCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $this->nullableString($data[$key]);
            }
        }
        if (array_key_exists('site_id', $data)) {
            $fields[] = 'site_id = :site_id';
            $params['site_id'] = (int) $data['site_id'];
        }
        if (array_key_exists('site_asset_id', $data)) {
            $fields[] = 'site_asset_id = :site_asset_id';
            $params['site_asset_id'] = $this->nullableInt($data['site_asset_id']);
        }
        if (array_key_exists('heartbeat_interval_seconds', $data)) {
            $fields[] = 'heartbeat_interval_seconds = :heartbeat_interval_seconds';
            $params['heartbeat_interval_seconds'] = max(1, (int) $data['heartbeat_interval_seconds']);
        }
        if (array_key_exists('stale_after_seconds', $data)) {
            $fields[] = 'stale_after_seconds = :stale_after_seconds';
            $params['stale_after_seconds'] = max(1, (int) $data['stale_after_seconds']);
        }
        if (array_key_exists('status', $data)) {
            $status = (string) $data['status'];
            if (!in_array($status, PosTerminal::ALLOWED_STATUSES, true)) {
                throw new InvalidArgumentException("Invalid status '{$status}'");
            }
            $fields[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE pos_terminals SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("pos_terminal {$id} not found after update");
        }
        return $row;
    }

    /**
     * Replace shared_secret (rotation). Caller is responsible for generating
     * a fresh 64-char hex string.
     */
    public function rotateSecret(int $id, string $newHexSecret): PosTerminal
    {
        if (strlen($newHexSecret) !== 64 || !ctype_xdigit($newHexSecret)) {
            throw new InvalidArgumentException('shared_secret must be 64 hex chars');
        }
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE pos_terminals SET shared_secret = :s WHERE id = :id'
        );
        $stmt->execute(['s' => $newHexSecret, 'id' => $id]);
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("pos_terminal {$id} not found after rotateSecret");
        }
        return $row;
    }

    /**
     * Hot-path denorm update. Called from PosHeartbeatIngestionService after
     * the heartbeat row is inserted. Also clears last_alert_ticket_id so a
     * future stale event re-opens a fresh ticket rather than re-using the
     * stale one.
     */
    public function recordHeartbeat(int $id, string $receivedAt, string $heartbeatStatus): PosTerminal
    {
        if (!in_array($heartbeatStatus, PosTerminal::ALLOWED_HEARTBEAT_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid heartbeat status '{$heartbeatStatus}'");
        }
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE pos_terminals
                SET last_seen_at = :seen,
                    last_status = :status,
                    last_alert_ticket_id = NULL
              WHERE id = :id'
        );
        $stmt->execute([
            'seen' => $receivedAt,
            'status' => $heartbeatStatus,
            'id' => $id,
        ]);
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("pos_terminal {$id} not found after recordHeartbeat");
        }
        return $row;
    }

    /**
     * Set the alert-ticket pointer so the cron sweeper doesn't re-fire while
     * the stale ticket is still open.
     */
    public function setAlertTicket(int $id, ?int $ticketId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE pos_terminals SET last_alert_ticket_id = :tid WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($ticketId === null) {
            $stmt->bindValue(':tid', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':tid', $ticketId, PDO::PARAM_INT);
        }
        $stmt->execute();
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM pos_terminals WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
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
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (terminal_code LIKE :search'
                . ' OR name LIKE :search'
                . ' OR vendor LIKE :search'
                . ' OR serial_number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['stale_after'])) {
            $where .= ' AND (last_seen_at IS NULL OR last_seen_at < :stale_after)';
            $params['stale_after'] = (string) $filters['stale_after'];
        }
        return [$where, $params];
    }
}
