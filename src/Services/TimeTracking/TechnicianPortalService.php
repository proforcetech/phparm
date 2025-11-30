<?php

namespace App\Services\TimeTracking;

use App\Database\Connection;
use PDO;

class TechnicianPortalService
{
    private Connection $connection;
    private TimeTrackingService $timeTracking;

    public function __construct(Connection $connection, TimeTrackingService $timeTracking)
    {
        $this->connection = $connection;
        $this->timeTracking = $timeTracking;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function assignedJobs(int $technicianId): array
    {
        $sql = <<<SQL
            SELECT j.id, j.title, e.number as estimate_number, c.name as customer_name, v.vin as vehicle_vin, j.customer_status
            FROM estimate_jobs j
            JOIN estimates e ON e.id = j.estimate_id
            JOIN customers c ON c.id = e.customer_id
            LEFT JOIN customer_vehicles v ON v.id = e.vehicle_id
            WHERE j.technician_id = :tech
            ORDER BY e.created_at DESC
        SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['tech' => $technicianId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function activeTimer(int $technicianId): ?array
    {
        $entry = $this->timeTracking->fetchOpenEntry($technicianId);
        return $entry?->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function timeHistory(int $technicianId): array
    {
        return array_map(static fn ($entry) => $entry->toArray(), $this->timeTracking->entriesForTechnician($technicianId));
    }
}
