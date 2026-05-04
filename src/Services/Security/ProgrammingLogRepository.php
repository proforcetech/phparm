<?php

namespace App\Services\Security;

use App\Database\Connection;
use App\Models\ProgrammingLog;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Append-only writer + reader for `programming_logs` — Phase 16 / S1 + S2 of
 * docs/woms-expansion-plan.md.
 *
 * Writes go through CredentialRegisterService / AccessScheduleService /
 * (later) PosTerminalService so the before/after snapshots are consistent
 * with the row state at programming time. Reads support the per-target
 * history view + a customer-wide audit feed.
 */
class ProgrammingLogRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id: int, site_id?: ?int, target_type: string,
     *              target_id: int, action: string, summary?: ?string,
     *              before_snapshot?: ?array<string, mixed>,
     *              after_snapshot?: ?array<string, mixed>,
     *              programmed_at?: ?string,
     *              programmed_by_user_id?: ?int,
     *              programmed_by_external?: ?string,
     *              ip_address?: ?string} $data
     */
    public function record(array $data): ProgrammingLog
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $targetType = (string) ($data['target_type'] ?? '');
        $targetId = (int) ($data['target_id'] ?? 0);
        $action = (string) ($data['action'] ?? '');
        if ($customerId <= 0 || $targetType === '' || $targetId <= 0 || $action === '') {
            throw new InvalidArgumentException(
                'customer_id, target_type, target_id, and action are required'
            );
        }
        if (!in_array($targetType, ProgrammingLog::ALLOWED_TARGET_TYPES, true)) {
            throw new InvalidArgumentException("Invalid target_type '{$targetType}'");
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO programming_logs
                (customer_id, site_id, target_type, target_id, action, summary,
                 before_snapshot, after_snapshot, programmed_at,
                 programmed_by_user_id, programmed_by_external, ip_address)
             VALUES
                (:customer_id, :site_id, :target_type, :target_id, :action, :summary,
                 :before_snapshot, :after_snapshot, :programmed_at,
                 :programmed_by_user_id, :programmed_by_external, :ip_address)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'site_id' => isset($data['site_id']) && $data['site_id'] !== null && $data['site_id'] !== ''
                ? (int) $data['site_id'] : null,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'action' => $action,
            'summary' => isset($data['summary']) ? (string) $data['summary'] : null,
            'before_snapshot' => $this->encodeJson($data['before_snapshot'] ?? null),
            'after_snapshot' => $this->encodeJson($data['after_snapshot'] ?? null),
            'programmed_at' => $data['programmed_at'] ?? date('Y-m-d H:i:s'),
            'programmed_by_user_id' => isset($data['programmed_by_user_id']) && $data['programmed_by_user_id']
                ? (int) $data['programmed_by_user_id'] : null,
            'programmed_by_external' => $data['programmed_by_external'] ?? null,
            'ip_address' => $data['ip_address'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created programming_log');
        }
        return $row;
    }

    public function findById(int $id): ?ProgrammingLog
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM programming_logs WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ProgrammingLog::fromRow($this->decodeRow($row)) : null;
    }

    /**
     * History for one target. Newest first.
     *
     * @return array<int, ProgrammingLog>
     */
    public function listForTarget(string $targetType, int $targetId, int $limit = 200): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM programming_logs
              WHERE target_type = :type AND target_id = :id
              ORDER BY programmed_at DESC, id DESC
              LIMIT :lim'
        );
        $stmt->bindValue(':type', $targetType, PDO::PARAM_STR);
        $stmt->bindValue(':id', $targetId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row) => ProgrammingLog::fromRow($this->decodeRow($row)),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Customer-wide audit feed with optional filters.
     *
     * @param array{customer_id?: int, site_id?: int, target_type?: string,
     *              action?: string, programmed_from?: string,
     *              programmed_to?: string} $filters
     * @return array<int, ProgrammingLog>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM programming_logs ' . $where
            . ' ORDER BY programmed_at DESC, id DESC'
            . ' LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            fn (array $row) => ProgrammingLog::fromRow($this->decodeRow($row)),
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
            'SELECT COUNT(*) FROM programming_logs ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
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
        if (!empty($filters['target_type'])) {
            $where .= ' AND target_type = :target_type';
            $params['target_type'] = (string) $filters['target_type'];
        }
        if (isset($filters['target_id'])) {
            $where .= ' AND target_id = :target_id';
            $params['target_id'] = (int) $filters['target_id'];
        }
        if (!empty($filters['action'])) {
            $where .= ' AND action = :action';
            $params['action'] = (string) $filters['action'];
        }
        if (!empty($filters['programmed_from'])) {
            $where .= ' AND programmed_at >= :programmed_from';
            $params['programmed_from'] = (string) $filters['programmed_from'];
        }
        if (!empty($filters['programmed_to'])) {
            $where .= ' AND programmed_at <= :programmed_to';
            $params['programmed_to'] = (string) $filters['programmed_to'];
        }

        return [$where, $params];
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
        foreach (['before_snapshot', 'after_snapshot'] as $key) {
            if (isset($row[$key]) && is_string($row[$key]) && $row[$key] !== '') {
                $decoded = json_decode($row[$key], true);
                $row[$key] = is_array($decoded) ? $decoded : null;
            }
        }
        return $row;
    }
}
