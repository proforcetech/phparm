<?php

namespace App\Services\POS;

use App\Models\PosHeartbeat;
use App\Models\PosTerminal;
use App\Models\ProgrammingLog;
use App\Models\User;
use App\Services\Security\ProgrammingLogRepository;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/pos/terminals — Phase 16 / S2 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   pos_terminals.view   — read terminals + heartbeat history + audit
 *   pos_terminals.manage — create/edit/delete terminals, rotate secret
 *
 * The shared_secret is only ever returned by store() and rotateSecret() —
 * those are the "show once" paths. Every other read redacts it to a boolean
 * presence flag.
 *
 * The webhook receiver itself lives in routes/modules/pos_terminals.php and
 * goes through PosHeartbeatIngestionService directly (no auth — HMAC is the
 * gate).
 */
class PosTerminalController
{
    public function __construct(
        private readonly PosTerminalRepository $terminals,
        private readonly PosHeartbeatRepository $heartbeats,
        private readonly ProgrammingLogRepository $logs,
        private readonly PosTerminalService $service,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{terminals: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $this->gate->assert($user, 'pos_terminals.view');

        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->terminals->list($filters, $perPage, $offset);
        $total = $this->terminals->count($filters);

        return [
            'terminals' => array_map(
                static fn (PosTerminal $row) => self::terminalToArray($row),
                $rows
            ),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'pos_terminals.view');

        $terminal = $this->terminals->findById($id);
        if ($terminal === null) {
            throw new InvalidArgumentException("pos_terminal {$id} not found");
        }
        $payload = self::terminalToArray($terminal);
        $payload['heartbeats'] = array_map(
            [self::class, 'heartbeatToArray'],
            $this->heartbeats->listForTerminal($id, 50)
        );
        $payload['heartbeat_total'] = $this->heartbeats->countForTerminal($id);
        $payload['programming_logs'] = array_map(
            [self::class, 'logToArray'],
            $this->logs->listForTarget(ProgrammingLog::TARGET_POS_TERMINAL, $id, 100)
        );
        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data, ?string $ip = null): array
    {
        $this->gate->assert($user, 'pos_terminals.manage');

        $result = $this->service->createTerminal($data, (int) $user->id, $ip);
        $payload = self::terminalToArray($result['terminal']);
        // Show the freshly-minted shared_secret exactly once.
        $payload['shared_secret'] = $result['shared_secret'];
        return $payload;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data, ?string $ip = null): array
    {
        $this->gate->assert($user, 'pos_terminals.manage');

        $terminal = $this->service->updateTerminal($id, $data, (int) $user->id, $ip);
        return self::terminalToArray($terminal);
    }

    /**
     * @return array<string, mixed>
     */
    public function rotateSecret(User $user, int $id, ?string $ip = null): array
    {
        $this->gate->assert($user, 'pos_terminals.manage');

        $result = $this->service->rotateSecret($id, (int) $user->id, $ip);
        $payload = self::terminalToArray($result['terminal']);
        $payload['shared_secret'] = $result['shared_secret'];
        return $payload;
    }

    public function destroy(User $user, int $id, ?string $ip = null): void
    {
        $this->gate->assert($user, 'pos_terminals.manage');

        $this->service->deleteTerminal($id, (int) $user->id, $ip);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listHeartbeats(User $user, int $id, int $page = 1, int $perPage = 100): array
    {
        $this->gate->assert($user, 'pos_terminals.view');

        if ($this->terminals->findById($id) === null) {
            throw new InvalidArgumentException("pos_terminal {$id} not found");
        }
        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        return array_map(
            [self::class, 'heartbeatToArray'],
            $this->heartbeats->listForTerminal($id, $perPage, $offset)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function terminalToArray(PosTerminal $row): array
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
            // Default response NEVER includes the secret; store/rotate paths
            // override.
            'shared_secret_present' => $row->shared_secret !== '',
            'heartbeat_interval_seconds' => $row->heartbeat_interval_seconds,
            'stale_after_seconds' => $row->stale_after_seconds,
            'status' => $row->status,
            'last_seen_at' => $row->last_seen_at,
            'last_status' => $row->last_status,
            'last_alert_ticket_id' => $row->last_alert_ticket_id,
            'is_stale' => $row->isStale(),
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function heartbeatToArray(PosHeartbeat $row): array
    {
        return [
            'id' => $row->id,
            'terminal_id' => $row->terminal_id,
            'received_at' => $row->received_at,
            'reported_at' => $row->reported_at,
            'status' => $row->status,
            'payload' => $row->payload,
            'ip_address' => $row->ip_address,
            'created_at' => $row->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function logToArray(ProgrammingLog $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'site_id' => $row->site_id,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'action' => $row->action,
            'summary' => $row->summary,
            'before_snapshot' => $row->before_snapshot,
            'after_snapshot' => $row->after_snapshot,
            'programmed_at' => $row->programmed_at,
            'programmed_by_user_id' => $row->programmed_by_user_id,
            'programmed_by_external' => $row->programmed_by_external,
            'ip_address' => $row->ip_address,
        ];
    }
}
