<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\ContractSite;
use PDO;
use RuntimeException;

/**
 * Contract-to-site scoping (Phase 4.1 of docs/expansion-plan.md).
 *
 * A contract with zero rows in this table applies company-wide.  Otherwise
 * it's restricted to the listed sites.
 */
class ContractSiteRepository
{
    private const COLUMNS = 'id, contract_id, site_id, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, ContractSite>
     */
    public function listForContract(int $contractId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_sites
             WHERE contract_id = :cid ORDER BY id ASC'
        );
        $stmt->execute(['cid' => $contractId]);
        return array_map(
            static fn(array $r) => new ContractSite($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function attach(int $contractId, int $siteId): ContractSite
    {
        $existing = $this->findByIds($contractId, $siteId);
        if ($existing !== null) {
            return $existing;
        }
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO contract_sites (contract_id, site_id) VALUES (:cid, :sid)'
        );
        $stmt->execute(['cid' => $contractId, 'sid' => $siteId]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contract_sites insert did not return a row');
        }
        return $found;
    }

    public function detach(int $contractId, int $siteId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM contract_sites WHERE contract_id = :cid AND site_id = :sid'
        );
        $stmt->execute(['cid' => $contractId, 'sid' => $siteId]);
    }

    public function findById(int $id): ?ContractSite
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_sites WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractSite($row) : null;
    }

    public function findByIds(int $contractId, int $siteId): ?ContractSite
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_sites
             WHERE contract_id = :cid AND site_id = :sid LIMIT 1'
        );
        $stmt->execute(['cid' => $contractId, 'sid' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractSite($row) : null;
    }
}
