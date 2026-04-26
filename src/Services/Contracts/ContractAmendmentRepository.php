<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\ContractAmendment;
use PDO;
use RuntimeException;

/**
 * Append-only change log for contracts (Phase 4.1 of docs/expansion-plan.md).
 * There is no update() / delete() — amendments are immutable history.
 */
class ContractAmendmentRepository
{
    public const KINDS = [
        'extend', 'change_scope', 'change_price', 'pause', 'terminate', 'renew',
    ];

    private const COLUMNS = 'id, contract_id, amendment_kind, effective_date,
        summary, delta_json, created_by_user_id, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, ContractAmendment>
     */
    public function listForContract(int $contractId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_amendments
             WHERE contract_id = :cid
             ORDER BY effective_date DESC, id DESC'
        );
        $stmt->execute(['cid' => $contractId]);
        return array_map(
            static fn(array $r) => new ContractAmendment($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?ContractAmendment
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_amendments WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractAmendment($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): ContractAmendment
    {
        $delta = $data['delta_json'] ?? null;
        if (is_array($delta)) {
            $delta = json_encode($delta, JSON_UNESCAPED_SLASHES);
        }
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO contract_amendments (
                contract_id, amendment_kind, effective_date, summary,
                delta_json, created_by_user_id
            ) VALUES (
                :contract_id, :amendment_kind, :effective_date, :summary,
                :delta_json, :created_by_user_id
            )'
        );
        $stmt->execute([
            'contract_id' => (int) $data['contract_id'],
            'amendment_kind' => $data['amendment_kind'],
            'effective_date' => $data['effective_date'],
            'summary' => $data['summary'],
            'delta_json' => $delta,
            'created_by_user_id' => $data['created_by_user_id'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contract_amendments insert did not return a row');
        }
        return $found;
    }
}
