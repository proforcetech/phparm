<?php

namespace App\Services\Pm;

use App\Database\Connection;
use App\Models\PmGeneration;
use PDO;

/**
 * pm_generations — append-only audit of every PM auto-generation attempt
 * (Phase 5.3 of docs/expansion-plan.md). Phase 5.4's compliance report reads
 * this to compute on-time / missed counts per schedule.
 */
class PmGenerationRepository
{
    public const STATUSES = ['generated', 'failed'];

    private const COLUMNS = 'id, schedule_id, plan_id, ticket_id, due_at,
        generated_at, status, failure_reason, consumption_applied_at,
        consumption_entitlement_id, consumption_amount, consumption_ledger_id';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): PmGeneration
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO pm_generations (
                schedule_id, plan_id, ticket_id, due_at, generated_at,
                status, failure_reason
            ) VALUES (
                :schedule_id, :plan_id, :ticket_id, :due_at, :generated_at,
                :status, :failure_reason
            )'
        );
        $stmt->execute([
            'schedule_id' => (int) $data['schedule_id'],
            'plan_id' => (int) $data['plan_id'],
            'ticket_id' => $data['ticket_id'] ?? null,
            'due_at' => (string) $data['due_at'],
            'generated_at' => $data['generated_at']
                ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'status' => $data['status'] ?? 'generated',
            'failure_reason' => $data['failure_reason'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new \RuntimeException('pm_generations insert did not return a row');
        }
        return $found;
    }

    /**
     * Record that consumption has been applied for this PM generation.
     * Called once per completion — subsequent calls throw to surface the
     * idempotency violation (caller should check consumption_applied_at first).
     */
    public function markConsumptionApplied(
        int $id,
        ?int $entitlementId,
        float $amount,
        ?int $ledgerId,
        ?string $appliedAt = null,
    ): PmGeneration {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE pm_generations SET
                consumption_applied_at = :applied_at,
                consumption_entitlement_id = :entitlement_id,
                consumption_amount = :amount,
                consumption_ledger_id = :ledger_id
             WHERE id = :id AND consumption_applied_at IS NULL'
        );
        $stmt->execute([
            'id' => $id,
            'applied_at' => $appliedAt ?? (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'entitlement_id' => $entitlementId,
            'amount' => $amount,
            'ledger_id' => $ledgerId,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException(
                "pm_generations {$id} already has consumption applied (or does not exist)"
            );
        }
        $found = $this->findById($id);
        if ($found === null) {
            throw new \RuntimeException("pm_generations {$id} disappeared after update");
        }
        return $found;
    }

    public function findById(int $id): ?PmGeneration
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM pm_generations WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PmGeneration($row) : null;
    }

    /**
     * @return array<int, PmGeneration>
     */
    public function listForSchedule(int $scheduleId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM pm_generations
             WHERE schedule_id = :sid
             ORDER BY generated_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['sid' => $scheduleId]);
        return array_map(
            fn(array $r) => new PmGeneration($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Aggregate counts for Phase 5.4 compliance reporting.
     *
     * @return array{generated: int, failed: int, total: int}
     */
    public function countsForScheduleSince(int $scheduleId, string $sinceDate): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT status, COUNT(*) AS n FROM pm_generations
             WHERE schedule_id = :sid AND due_at >= :since
             GROUP BY status'
        );
        $stmt->execute(['sid' => $scheduleId, 'since' => $sinceDate]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = ['generated' => 0, 'failed' => 0, 'total' => 0];
        foreach ($rows as $r) {
            $s = (string) $r['status'];
            $n = (int) $r['n'];
            if ($s === 'generated') {
                $out['generated'] = $n;
            } elseif ($s === 'failed') {
                $out['failed'] = $n;
            }
            $out['total'] += $n;
        }
        return $out;
    }
}
