<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionReport;
use App\Services\Inspection\InspectionCompletionService;
use InvalidArgumentException;
use PDO;

class InspectionPortalService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, InspectionReport>
     */
    public function listForCustomer(int $customerId): array
    {
        $sql = 'SELECT * FROM inspection_reports WHERE customer_id = :customer_id ORDER BY created_at DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['customer_id' => $customerId]);

        return array_map(static fn (array $row) => new InspectionReport($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detailForCustomer(int $customerId, int $reportId): ?array
    {
        $this->assertOwnership($customerId, $reportId);

        $completion = new InspectionCompletionService($this->connection);

        return $completion->detail($reportId);
    }

    public function show(int $customerId, int $reportId): ?InspectionReport
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inspection_reports WHERE id = :id AND customer_id = :customer_id LIMIT 1');
        $stmt->execute(['id' => $reportId, 'customer_id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new InspectionReport($row) : null;
    }

    public function assertOwnership(int $customerId, int $reportId): void
    {
        if ($this->show($customerId, $reportId) === null) {
            throw new InvalidArgumentException('Inspection not found for this customer.');
        }
    }
}
