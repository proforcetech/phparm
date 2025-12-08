<?php

namespace App\Services\TimeTracking;

use App\Database\Connection;
use App\Models\TechnicianJob;
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

        return array_map(
            static fn (array $row) => new TechnicianJob($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * @return array<int, TechnicianJob>
     */
    public function getAssignedJobs(int $technicianId): array
    {
        return $this->assignedJobs($technicianId);
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

    /**
     * @return array<string, mixed>
     */
    public function summary(int $technicianId): array
    {
        $history = $this->timeHistory($technicianId);
        $today = new \DateTimeImmutable('today');
        $week = (new \DateTimeImmutable('today'))->modify('monday this week');

        $todayMinutes = 0;
        $weekMinutes = 0;

        foreach ($history as $entry) {
            $startedAt = new \DateTimeImmutable($entry['started_at']);
            $minutes = (float) ($entry['duration_minutes'] ?? 0);

            if ($startedAt >= $today) {
                $todayMinutes += $minutes;
            }

            if ($startedAt >= $week) {
                $weekMinutes += $minutes;
            }
        }

        return [
            'jobs' => $this->assignedJobs($technicianId),
            'active_entry' => $this->activeTimer($technicianId),
            'history' => $history,
            'totals' => [
                'today_minutes' => $todayMinutes,
                'week_minutes' => $weekMinutes,
            ],
        ];
    }
}
