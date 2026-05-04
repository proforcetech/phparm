<?php

namespace App\Services\POS;

use App\Database\Connection;
use App\Models\PosHeartbeat;
use App\Models\PosTerminal;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Append-only writer + reader for `pos_heartbeats` — Phase 16 / S2 of
 * docs/woms-expansion-plan.md.
 *
 * Each insert is paired with a denorm update on `pos_terminals.last_seen_at`
 * inside one transaction at the service layer (PosHeartbeatIngestionService)
 * so the dashboard "is it up?" question stays cheap.
 */
class PosHeartbeatRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{terminal_id: int, received_at?: string,
     *              reported_at?: ?string, status?: string,
     *              payload?: array<string, mixed>|null,
     *              ip_address?: ?string} $data
     */
    public function record(array $data): PosHeartbeat
    {
        $terminalId = (int) ($data['terminal_id'] ?? 0);
        if ($terminalId <= 0) {
            throw new InvalidArgumentException('terminal_id is required');
        }
        $status = (string) ($data['status'] ?? PosTerminal::HEARTBEAT_ONLINE);
        if (!in_array($status, PosTerminal::ALLOWED_HEARTBEAT_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid heartbeat status '{$status}'");
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO pos_heartbeats
                (terminal_id, received_at, reported_at, status, payload, ip_address)
             VALUES
                (:terminal_id, :received_at, :reported_at, :status, :payload, :ip_address)'
        );
        $stmt->execute([
            'terminal_id' => $terminalId,
            'received_at' => $data['received_at'] ?? date('Y-m-d H:i:s'),
            'reported_at' => $data['reported_at'] ?? null,
            'status' => $status,
            'payload' => $this->encodeJson($data['payload'] ?? null),
            'ip_address' => $data['ip_address'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created pos_heartbeat');
        }
        return $row;
    }

    public function findById(int $id): ?PosHeartbeat
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM pos_heartbeats WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? PosHeartbeat::fromRow($this->decodeRow($row)) : null;
    }

    /**
     * Newest-first heartbeats for a terminal (uses the
     * (terminal_id, received_at) composite index).
     *
     * @return array<int, PosHeartbeat>
     */
    public function listForTerminal(int $terminalId, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM pos_heartbeats
              WHERE terminal_id = :tid
              ORDER BY received_at DESC, id DESC
              LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':tid', $terminalId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':off', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row) => PosHeartbeat::fromRow($this->decodeRow($row)),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function countForTerminal(int $terminalId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM pos_heartbeats WHERE terminal_id = :tid'
        );
        $stmt->execute(['tid' => $terminalId]);
        return (int) $stmt->fetchColumn();
    }

    public function latestForTerminal(int $terminalId): ?PosHeartbeat
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM pos_heartbeats
              WHERE terminal_id = :tid
              ORDER BY received_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['tid' => $terminalId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? PosHeartbeat::fromRow($this->decodeRow($row)) : null;
    }

    /**
     * @param array<string, mixed>|null $value
     */
    private function encodeJson(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function decodeRow(array $row): array
    {
        if (isset($row['payload']) && is_string($row['payload']) && $row['payload'] !== '') {
            $decoded = json_decode($row['payload'], true);
            $row['payload'] = is_array($decoded) ? $decoded : null;
        }
        return $row;
    }
}
