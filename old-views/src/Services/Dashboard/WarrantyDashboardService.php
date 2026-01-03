<?php

namespace App\Services\Dashboard;

use App\Database\Connection;
use PDO;

class WarrantyDashboardService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array{open:int,resolved:int,rejected:int,awaiting_customer:int,reviewing:int}
     */
    public function counters(): array
    {
        $sql = <<<SQL
            SELECT status, COUNT(*) as total
            FROM warranty_claims
            GROUP BY status
        SQL;

        $stmt = $this->connection->pdo()->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        return [
            'open' => (int) ($rows['open'] ?? 0),
            'reviewing' => (int) ($rows['reviewing'] ?? 0),
            'awaiting_customer' => (int) ($rows['awaiting_customer'] ?? 0),
            'resolved' => (int) ($rows['resolved'] ?? 0),
            'rejected' => (int) ($rows['rejected'] ?? 0),
        ];
    }
}
