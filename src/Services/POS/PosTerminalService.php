<?php

namespace App\Services\POS;

use App\Database\Connection;
use App\Models\PosTerminal;
use App\Models\ProgrammingLog;
use App\Services\Security\ProgrammingLogRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use Throwable;

/**
 * Phase 16 / S2 of docs/woms-expansion-plan.md — POS terminal config writes.
 *
 * Wraps `PosTerminalRepository` and pairs each config-change with a
 * `programming_logs` row carrying before/after JSON snapshots. The
 * shared_secret is never serialized into the snapshot or returned by API
 * responses except through the explicit rotateSecret() return path.
 *
 * Heartbeat ingestion is a separate concern handled by
 * `PosHeartbeatIngestionService` so the webhook hot-path is decoupled from
 * the admin CRUD surface.
 */
class PosTerminalService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PosTerminalRepository $terminals,
        private readonly ProgrammingLogRepository $logs,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @return array{terminal: PosTerminal, shared_secret: string}
     *
     * Returns the freshly-minted shared_secret so the admin UI can show it
     * exactly once. On every subsequent read, the secret is redacted out of
     * the API response.
     */
    public function createTerminal(array $data, int $actorUserId, ?string $ip = null): array
    {
        $secret = $this->mintSecret();
        $data['shared_secret'] = $secret;

        $terminal = $this->withTransaction(fn () => $this->terminals->create($data));

        $this->writeLog([
            'customer_id' => $terminal->customer_id,
            'site_id' => $terminal->site_id,
            'target_type' => ProgrammingLog::TARGET_POS_TERMINAL,
            'target_id' => $terminal->id,
            'action' => ProgrammingLog::ACTION_CREATED,
            'summary' => sprintf('POS terminal "%s" registered', $terminal->terminal_code),
            'after_snapshot' => $this->terminalSnapshot($terminal),
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'pos_terminal.created',
            'pos_terminal',
            (string) $terminal->id,
            $actorUserId,
            ['customer_id' => $terminal->customer_id, 'terminal_code' => $terminal->terminal_code]
        ));

        return ['terminal' => $terminal, 'shared_secret' => $secret];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateTerminal(int $id, array $data, int $actorUserId, ?string $ip = null): PosTerminal
    {
        $existing = $this->terminals->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pos_terminal {$id} not found");
        }
        $before = $this->terminalSnapshot($existing);
        $updated = $this->withTransaction(fn () => $this->terminals->update($id, $data));
        $after = $this->terminalSnapshot($updated);

        $action = $existing->status !== $updated->status
            ? ($updated->status === PosTerminal::STATUS_ACTIVE
                ? ProgrammingLog::ACTION_ENABLED
                : ProgrammingLog::ACTION_DISABLED)
            : ProgrammingLog::ACTION_CONFIG_CHANGED;

        $this->writeLog([
            'customer_id' => $updated->customer_id,
            'site_id' => $updated->site_id,
            'target_type' => ProgrammingLog::TARGET_POS_TERMINAL,
            'target_id' => $updated->id,
            'action' => $action,
            'summary' => sprintf(
                'POS terminal "%s" config updated%s',
                $updated->terminal_code,
                $existing->status !== $updated->status
                    ? " ({$existing->status} → {$updated->status})"
                    : ''
            ),
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'pos_terminal.updated',
            'pos_terminal',
            (string) $updated->id,
            $actorUserId,
            ['changed_keys' => array_keys($this->diffKeys($before, $after))]
        ));
        return $updated;
    }

    /**
     * Generate a fresh shared_secret and persist it. Returns the new
     * plaintext to the caller (admin UI shows it once); subsequent reads
     * redact it.
     *
     * @return array{terminal: PosTerminal, shared_secret: string}
     */
    public function rotateSecret(int $id, int $actorUserId, ?string $ip = null): array
    {
        $existing = $this->terminals->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pos_terminal {$id} not found");
        }
        $secret = $this->mintSecret();
        $updated = $this->withTransaction(fn () => $this->terminals->rotateSecret($id, $secret));

        $this->writeLog([
            'customer_id' => $updated->customer_id,
            'site_id' => $updated->site_id,
            'target_type' => ProgrammingLog::TARGET_POS_TERMINAL,
            'target_id' => $updated->id,
            'action' => ProgrammingLog::ACTION_CONFIG_CHANGED,
            'summary' => sprintf('POS terminal "%s" shared_secret rotated', $updated->terminal_code),
            'before_snapshot' => ['shared_secret_present' => true],
            'after_snapshot' => ['shared_secret_present' => true, 'rotated_at' => date('Y-m-d H:i:s')],
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'pos_terminal.secret_rotated',
            'pos_terminal',
            (string) $updated->id,
            $actorUserId,
            ['terminal_code' => $updated->terminal_code]
        ));
        return ['terminal' => $updated, 'shared_secret' => $secret];
    }

    public function deleteTerminal(int $id, int $actorUserId, ?string $ip = null): void
    {
        $existing = $this->terminals->findById($id);
        if ($existing === null) {
            return;
        }
        $before = $this->terminalSnapshot($existing);
        $this->withTransaction(function () use ($id) {
            $this->terminals->delete($id);
        });
        $this->writeLog([
            'customer_id' => $existing->customer_id,
            'site_id' => $existing->site_id,
            'target_type' => ProgrammingLog::TARGET_POS_TERMINAL,
            'target_id' => $existing->id,
            'action' => ProgrammingLog::ACTION_DELETED,
            'summary' => sprintf('POS terminal "%s" deleted', $existing->terminal_code),
            'before_snapshot' => $before,
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'pos_terminal.deleted',
            'pos_terminal',
            (string) $existing->id,
            $actorUserId,
            ['terminal_code' => $existing->terminal_code]
        ));
    }

    /**
     * Cryptographically-random 64-char hex string.
     */
    private function mintSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * @return array<string, mixed>
     */
    private function terminalSnapshot(PosTerminal $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'site_id' => $row->site_id,
            'site_asset_id' => $row->site_asset_id,
            'terminal_code' => $row->terminal_code,
            'name' => $row->name,
            'vendor' => $row->vendor,
            'model' => $row->model,
            'serial_number' => $row->serial_number,
            // shared_secret is intentionally omitted from the audit ledger.
            'shared_secret_present' => $row->shared_secret !== '',
            'heartbeat_interval_seconds' => $row->heartbeat_interval_seconds,
            'stale_after_seconds' => $row->stale_after_seconds,
            'status' => $row->status,
            'last_seen_at' => $row->last_seen_at,
            'last_status' => $row->last_status,
            'notes' => $row->notes,
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diffKeys(array $a, array $b): array
    {
        $diff = [];
        foreach ($b as $k => $v) {
            if (!array_key_exists($k, $a) || $a[$k] !== $v) {
                $diff[$k] = ['from' => $a[$k] ?? null, 'to' => $v];
            }
        }
        return $diff;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeLog(array $data): void
    {
        try {
            $this->logs->record($data);
        } catch (Throwable $e) {
            $this->audit->log(new AuditEntry(
                'programming_log.write_failed',
                $data['target_type'] ?? 'unknown',
                isset($data['target_id']) ? (string) $data['target_id'] : null,
                isset($data['programmed_by_user_id']) ? (int) $data['programmed_by_user_id'] : null,
                ['error' => $e->getMessage()]
            ));
        }
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function withTransaction(callable $work)
    {
        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $result = $work();
            if ($startedTx) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
