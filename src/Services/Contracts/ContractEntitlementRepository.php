<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\ContractEntitlement;
use PDO;
use RuntimeException;

/**
 * What the contract includes (Phase 4.1 of docs/expansion-plan.md).
 *
 * quantity_used is mutated by downstream phases:
 *   - Phase 4.4 increments it when labor hours / visits are consumed
 *   - Phase 4.3 resets it at period boundaries
 *   - Phase 4.5 reads it to refuse overages (or warn)
 *
 * The `consume()` method is the safe atomic increment path; callers should
 * never `update(['quantity_used' => ...])` directly.
 */
class ContractEntitlementRepository
{
    public const KINDS = ['hours', 'visits', 'tickets', 'coverage'];

    public const PERIODS = ['term', 'annual', 'quarterly', 'monthly'];

    private const COLUMNS = 'id, contract_id, entitlement_kind, description,
        quantity_allowed, quantity_used, period, period_resets_at,
        reset_on_renewal, sla_policy_id, unit_rate_cents, notes, is_active,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, ContractEntitlement>
     */
    public function listForContract(int $contractId, bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM contract_entitlements WHERE contract_id = :cid';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['cid' => $contractId]);
        return array_map(
            static fn(array $r) => new ContractEntitlement($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?ContractEntitlement
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_entitlements WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractEntitlement($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ContractEntitlement
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO contract_entitlements (
                contract_id, entitlement_kind, description, quantity_allowed,
                period, reset_on_renewal, sla_policy_id, unit_rate_cents,
                notes, is_active
            ) VALUES (
                :contract_id, :entitlement_kind, :description, :quantity_allowed,
                :period, :reset_on_renewal, :sla_policy_id, :unit_rate_cents,
                :notes, :is_active
            )'
        );
        $stmt->execute([
            'contract_id' => (int) $data['contract_id'],
            'entitlement_kind' => $data['entitlement_kind'],
            'description' => $data['description'],
            'quantity_allowed' => $data['quantity_allowed'] ?? null,
            'period' => $data['period'] ?? 'term',
            'reset_on_renewal' => isset($data['reset_on_renewal'])
                ? (int) (bool) $data['reset_on_renewal'] : 1,
            'sla_policy_id' => $data['sla_policy_id'] ?? null,
            'unit_rate_cents' => $data['unit_rate_cents'] ?? null,
            'notes' => $data['notes'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contract_entitlements insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): ContractEntitlement
    {
        $writable = [
            'entitlement_kind', 'description', 'quantity_allowed',
            'period', 'reset_on_renewal', 'sla_policy_id', 'unit_rate_cents',
            'notes', 'is_active',
        ];
        $boolCols = ['reset_on_renewal', 'is_active'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $value = $data[$col];
                if (in_array($col, $boolCols, true) && $value !== null) {
                    $value = (int) (bool) $value;
                }
                $params[$col] = $value;
            }
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("contract_entitlement {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE contract_entitlements SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("contract_entitlement {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM contract_entitlements WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Atomic increment of quantity_used. Returns the refreshed row. The
     * caller is responsible for deciding whether the increment should be
     * allowed — this method will not refuse to exceed quantity_allowed
     * (billing logic in Phase 4.4 decides what happens on overage).
     */
    public function consume(int $id, float $amount): ContractEntitlement
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE contract_entitlements
             SET quantity_used = quantity_used + :amt
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'amt' => $amount]);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("contract_entitlement {$id} not found");
        }
        return $found;
    }

    /**
     * Reset quantity_used on the entitlements flagged reset_on_renewal. Used
     * by Phase 4.3 auto-renew, but exposed as its own method so a manual
     * "zero out usage" admin action can reuse it.
     */
    public function resetUsageForContract(int $contractId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE contract_entitlements
             SET quantity_used = 0
             WHERE contract_id = :cid AND reset_on_renewal = 1'
        );
        $stmt->execute(['cid' => $contractId]);
        return $stmt->rowCount();
    }
}
