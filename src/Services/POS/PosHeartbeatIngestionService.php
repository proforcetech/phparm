<?php

namespace App\Services\POS;

use App\Database\Connection;
use App\Models\PosHeartbeat;
use App\Models\PosTerminal;
use App\Models\ProgrammingLog;
use App\Services\Security\ProgrammingLogRepository;
use App\Services\Tickets\TicketRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use Throwable;

/**
 * Phase 16 / S2 of docs/woms-expansion-plan.md — POS heartbeat webhook
 * ingestion + alert-ticket auto-close.
 *
 * Hot path: receive() runs from the public webhook receiver, verifies the
 * HMAC, inserts the heartbeat row, updates the per-terminal denorm
 * (last_seen_at / last_status), writes a `programming_logs` row with
 * action=webhook_received, and — if the terminal had an open stale-alert
 * ticket — appends a 'recovered' note and clears `last_alert_ticket_id` so
 * the cron sweeper considers the terminal healthy again.
 *
 * The inner heartbeat insert + denorm update + alert-ticket clear happen in
 * one transaction so the dashboard never sees a half-applied state. The
 * programming_log write is advisory (failure does not roll back) — it goes
 * through writeLog() which swallows the throw and reports it via the audit
 * logger.
 */
class PosHeartbeatIngestionService
{
    public const RESULT_OK = 'ok';
    public const RESULT_BAD_SIGNATURE = 'bad_signature';
    public const RESULT_UNKNOWN_TERMINAL = 'unknown_terminal';
    public const RESULT_DISABLED_TERMINAL = 'disabled_terminal';
    public const RESULT_BAD_PAYLOAD = 'bad_payload';

    public function __construct(
        private readonly Connection $connection,
        private readonly PosTerminalRepository $terminals,
        private readonly PosHeartbeatRepository $heartbeats,
        private readonly ProgrammingLogRepository $logs,
        private readonly TicketRepository $tickets,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Verify + record an inbound heartbeat. Caller should map the result to
     * the appropriate HTTP status (4xx for the bad_* cases, 200 for ok).
     *
     * @return array{result: string, terminal: ?PosTerminal, heartbeat: ?PosHeartbeat, message: string}
     */
    public function receive(
        string $terminalCode,
        string $signatureHeader,
        string $rawBody,
        ?string $sourceIp = null,
    ): array {
        $terminal = $this->terminals->findByCodeAcrossCustomers($terminalCode);
        if ($terminal === null) {
            return $this->result(self::RESULT_UNKNOWN_TERMINAL, null, null, 'unknown terminal_code');
        }
        if ($terminal->status !== PosTerminal::STATUS_ACTIVE) {
            return $this->result(
                self::RESULT_DISABLED_TERMINAL,
                $terminal,
                null,
                "terminal status is {$terminal->status}"
            );
        }
        if (!$this->verifySignature($terminal->shared_secret, $rawBody, $signatureHeader)) {
            // Don't write a programming_log row for failed signatures —
            // attackers can drown the audit log. Audit logger only.
            $this->audit->log(new AuditEntry(
                'pos_heartbeat.bad_signature',
                'pos_terminal',
                (string) $terminal->id,
                null,
                ['ip' => $sourceIp, 'terminal_code' => $terminal->terminal_code]
            ));
            return $this->result(self::RESULT_BAD_SIGNATURE, $terminal, null, 'invalid signature');
        }

        $payload = $this->decodePayload($rawBody);
        if ($payload === null) {
            return $this->result(self::RESULT_BAD_PAYLOAD, $terminal, null, 'payload is not valid JSON');
        }

        $reportedStatus = $this->normalizeStatus((string) ($payload['status'] ?? PosTerminal::HEARTBEAT_ONLINE));
        $reportedAt = $this->normalizeReportedAt($payload['reported_at'] ?? null);
        $now = date('Y-m-d H:i:s');

        $heartbeat = null;
        $hadOpenAlert = $terminal->last_alert_ticket_id !== null;

        try {
            $this->withTransaction(function () use (
                $terminal,
                $reportedStatus,
                $reportedAt,
                $payload,
                $sourceIp,
                $now,
                $hadOpenAlert,
                &$heartbeat
            ): void {
                $heartbeat = $this->heartbeats->record([
                    'terminal_id' => $terminal->id,
                    'received_at' => $now,
                    'reported_at' => $reportedAt,
                    'status' => $reportedStatus,
                    'payload' => $payload,
                    'ip_address' => $sourceIp,
                ]);
                $this->terminals->recordHeartbeat($terminal->id, $now, $reportedStatus);

                if ($hadOpenAlert) {
                    // Auto-recover: mark the alert ticket resolved with a
                    // recovery note. The pointer is cleared above by
                    // recordHeartbeat() so the sweeper won't re-fire on this
                    // terminal until it goes stale again.
                    $this->resolveAlertTicket(
                        (int) $terminal->last_alert_ticket_id,
                        $terminal,
                        $now
                    );
                }
            });
        } catch (Throwable $e) {
            $this->audit->log(new AuditEntry(
                'pos_heartbeat.ingest_failed',
                'pos_terminal',
                (string) $terminal->id,
                null,
                ['error' => $e->getMessage(), 'ip' => $sourceIp]
            ));
            throw $e;
        }

        $this->writeLog([
            'customer_id' => $terminal->customer_id,
            'site_id' => $terminal->site_id,
            'target_type' => ProgrammingLog::TARGET_POS_TERMINAL,
            'target_id' => $terminal->id,
            'action' => ProgrammingLog::ACTION_WEBHOOK_RECEIVED,
            'summary' => sprintf(
                'Heartbeat received from "%s" (status=%s)%s',
                $terminal->terminal_code,
                $reportedStatus,
                $hadOpenAlert ? ', alert ticket auto-resolved' : ''
            ),
            'after_snapshot' => [
                'heartbeat_id' => $heartbeat?->id,
                'received_at' => $now,
                'reported_at' => $reportedAt,
                'reported_status' => $reportedStatus,
                'recovered_alert_ticket_id' => $hadOpenAlert ? $terminal->last_alert_ticket_id : null,
            ],
            'programmed_by_external' => 'POS-WEBHOOK',
            'ip_address' => $sourceIp,
        ]);

        return $this->result(self::RESULT_OK, $terminal, $heartbeat, 'ok');
    }

    /**
     * Constant-time HMAC-SHA256 comparison. Accepts either the raw hex digest
     * in the header or the prefixed form "sha256=<hex>" (we tolerate both
     * because devices in the wild use both conventions).
     */
    private function verifySignature(string $sharedSecretHex, string $rawBody, string $signatureHeader): bool
    {
        if ($sharedSecretHex === '' || $signatureHeader === '') {
            return false;
        }
        $provided = trim($signatureHeader);
        if (str_starts_with($provided, 'sha256=')) {
            $provided = substr($provided, 7);
        }
        $provided = strtolower($provided);
        if (!ctype_xdigit($provided)) {
            return false;
        }

        // Shared secret is stored as 64 hex chars but the underlying entropy
        // is 32 raw bytes — use the bytes as the HMAC key.
        $key = @hex2bin($sharedSecretHex);
        if ($key === false || $key === '') {
            return false;
        }
        $expected = hash_hmac('sha256', $rawBody, $key);
        return hash_equals($expected, $provided);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePayload(string $rawBody): ?array
    {
        if ($rawBody === '') {
            // Empty body is fine — the device may just be saying "I'm alive".
            return [];
        }
        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            return null;
        }
        return $decoded;
    }

    private function normalizeStatus(string $candidate): string
    {
        $candidate = strtolower(trim($candidate));
        if (in_array($candidate, PosTerminal::ALLOWED_HEARTBEAT_STATUSES, true)) {
            return $candidate;
        }
        return PosTerminal::HEARTBEAT_ONLINE;
    }

    private function normalizeReportedAt(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if (!$ts) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function resolveAlertTicket(int $ticketId, PosTerminal $terminal, string $now): void
    {
        $ticket = $this->tickets->findById($ticketId);
        if ($ticket === null) {
            return;
        }
        // Only auto-flip our own POS-sourced tickets so we don't stomp on a
        // human-managed parent ticket someone re-purposed.
        if (($ticket->source ?? '') !== 'pos-sweeper') {
            return;
        }
        if (in_array($ticket->status, ['resolved', 'closed', 'cancelled'], true)) {
            return;
        }
        $this->tickets->update($ticketId, [
            'status' => 'resolved',
            'resolution_code' => 'auto_recovered',
            'resolved_at' => $now,
        ]);
        $this->audit->log(new AuditEntry(
            'pos_heartbeat.alert_auto_resolved',
            'ticket',
            (string) $ticketId,
            null,
            [
                'terminal_id' => $terminal->id,
                'terminal_code' => $terminal->terminal_code,
            ]
        ));
    }

    /**
     * @return array{result: string, terminal: ?PosTerminal, heartbeat: ?PosHeartbeat, message: string}
     */
    private function result(string $code, ?PosTerminal $terminal, ?PosHeartbeat $heartbeat, string $message): array
    {
        return [
            'result' => $code,
            'terminal' => $terminal,
            'heartbeat' => $heartbeat,
            'message' => $message,
        ];
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
                null,
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
