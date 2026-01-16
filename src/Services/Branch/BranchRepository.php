<?php

namespace App\Services\Branch;

use App\Database\Connection;
use PDO;

class BranchRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, array{id: int, label: string}>
     */
    public function listBranches(?int $branchId = null): array
    {
        $sql = 'SELECT DISTINCT branch_id FROM workorders WHERE branch_id IS NOT NULL';
        $params = [];

        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch_id';
            $params['branch_id'] = $branchId;
        }

        $sql .= ' ORDER BY branch_id';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_map(static fn ($id) => [
            'id' => (int) $id,
            'label' => sprintf('Branch #%d', (int) $id),
        ], $rows);
    }
}
