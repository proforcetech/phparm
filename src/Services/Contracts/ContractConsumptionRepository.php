<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\ContractConsumptionLedger;
use PDO;
use RuntimeException;

/**
 * Append-only ledger for contract consumption (Phase 4.4 of
 * docs/expansion-plan.md). Paired with the atomic quantity_used increment
 * on contract_entitlements — the two must be written together.
 */
class ContractConsumptionRepository
{
    public const SOURCE_TYPES = ['ticket', 'workorder', 'invoice', 'manual'];

    private const COLUMNS = 'id, contract_id, entitlement_id, source_type,
        source_id, entitlement_kind, amount_requested, amount_covered,
        amount_overage, unit_rate_cents, notes, actor_user_id,
        occurred_at, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function record(array $data): ContractConsumptionLedger
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO contract_consumption_ledger (
                contract_id, entitlement_id, source_type, source_id,
                entitlement_kind, amount_requested, amount_covered,
                amount_overage, unit_rate_cents, notes, actor_user_id,
                occurred_at
             ) VALUES (
                :cid, :eid, :stype, :sid, :kind, :req, :cov, :over,
                :rate, :notes, :uid, :occurred
             )'
        );
        $stmt->execute([
            'cid' => (int) $data['contract_id'],
            'eid' => $data['entitlement_id'] ?? null,
            'stype' => (string) $data['source_type'],
            'sid' => $data['source_id'] ?? null,
            'kind' => (string) $data['entitlement_kind'],
            'req' => (string) $data['amount_requested'],
            'cov' => (string) ($data['amount_covered'] ?? '0'),
            'over' => (string) ($data['amount_overage'] ?? '0'),
            'rate' => $data['unit_rate_cents'] ?? null,
            'notes' => $data['notes'] ?? null,
            'uid' => $data['actor_user_id'] ?? null,
            'occurred' => $data['occurred_at'] ?? date('Y-m-d H:i:s'),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contract_consumption_ledger insert did not return a row');
        }
        return $found;
    }

    public function findById(int $id): ?ContractConsumptionLedger
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_consumption_ledger
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractConsumptionLedger($row) : null;
    }

    /**
     * @return array<int, ContractConsumptionLedger>
     */
    public function listForContract(int $contractId, int $limit = 500): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_consumption_ledger
             WHERE contract_id = :cid
             ORDER BY occurred_at DESC, id DESC
             LIMIT ' . max(1, $limit)
        );
        $stmt->execute(['cid' => $contractId]);
        return array_map(
            static fn(array $r) => new ContractConsumptionLedger($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, ContractConsumptionLedger>
     */
    public function listForSource(string $sourceType, int $sourceId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_consumption_ledger
             WHERE source_type = :st AND source_id = :sid
             ORDER BY occurred_at DESC, id DESC'
        );
        $stmt->execute(['st' => $sourceType, 'sid' => $sourceId]);
        return array_map(
            static fn(array $r) => new ContractConsumptionLedger($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }
}
