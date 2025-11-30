<?php

namespace App\Services\TimeTracking;

use App\Database\Connection;
use App\Models\TimeEntry;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class TimeTrackingService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    public function start(int $technicianId, ?int $estimateJobId = null, ?float $lat = null, ?float $lng = null): TimeEntry
    {
        $open = $this->fetchOpenEntry($technicianId);
        if ($open !== null) {
            throw new InvalidArgumentException('Technician already has an active timer.');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO time_entries (technician_id, estimate_job_id, started_at, start_latitude, start_longitude, manual_override, created_at, updated_at) VALUES (:technician_id, :estimate_job_id, NOW(), :lat, :lng, 0, NOW(), NOW())'
        );
        $stmt->execute([
            'technician_id' => $technicianId,
            'estimate_job_id' => $estimateJobId,
            'lat' => $lat,
            'lng' => $lng,
        ]);

        $entryId = (int) $this->connection->pdo()->lastInsertId();
        $entry = $this->find($entryId);
        $this->log($technicianId, 'time.start', $entryId, $entry?->toArray() ?? []);

        return $entry ?? new TimeEntry(['id' => $entryId]);
    }

    public function stop(int $entryId, int $actorId, ?float $lat = null, ?float $lng = null): ?TimeEntry
    {
        $entry = $this->find($entryId);
        if ($entry === null || $entry->ended_at !== null) {
            return null;
        }

        $endedAt = new DateTimeImmutable();
        $startedAt = new DateTimeImmutable($entry->started_at);
        $minutes = ($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE time_entries SET ended_at = :ended_at, end_latitude = :lat, end_longitude = :lng, duration_minutes = :minutes, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $entryId,
            'ended_at' => $endedAt->format('Y-m-d H:i:s'),
            'lat' => $lat,
            'lng' => $lng,
            'minutes' => $minutes,
        ]);

        $updated = $this->find($entryId);
        $this->log($actorId, 'time.stop', $entryId, [
            'duration_minutes' => $minutes,
        ]);

        return $updated;
    }

    public function manualEntry(
        int $technicianId,
        string $startedAt,
        string $endedAt,
        ?int $estimateJobId = null,
        ?string $notes = null,
        bool $override = true
    ): TimeEntry {
        $start = new DateTimeImmutable($startedAt);
        $end = new DateTimeImmutable($endedAt);
        $minutes = max(0, ($end->getTimestamp() - $start->getTimestamp()) / 60);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO time_entries (technician_id, estimate_job_id, started_at, ended_at, duration_minutes, manual_override, notes, created_at, updated_at) VALUES (:technician_id, :estimate_job_id, :started_at, :ended_at, :minutes, :override, :notes, NOW(), NOW())'
        );
        $stmt->execute([
            'technician_id' => $technicianId,
            'estimate_job_id' => $estimateJobId,
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
            'minutes' => $minutes,
            'override' => $override ? 1 : 0,
            'notes' => $notes,
        ]);

        $entryId = (int) $this->connection->pdo()->lastInsertId();
        $this->log($technicianId, 'time.manual', $entryId, ['minutes' => $minutes]);

        return $this->find($entryId) ?? new TimeEntry(['id' => $entryId]);
    }

    public function find(int $entryId): ?TimeEntry
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM time_entries WHERE id = :id');
        $stmt->execute(['id' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new TimeEntry($row);
    }

    public function fetchOpenEntry(int $technicianId): ?TimeEntry
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM time_entries WHERE technician_id = :tech AND ended_at IS NULL ORDER BY started_at DESC LIMIT 1');
        $stmt->execute(['tech' => $technicianId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new TimeEntry($row);
    }

    public function entriesForTechnician(int $technicianId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM time_entries WHERE technician_id = :tech ORDER BY started_at DESC');
        $stmt->execute(['tech' => $technicianId]);

        return array_map(static fn (array $row) => new TimeEntry($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function log(int $actorId, string $event, int $entryId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'time_entry', (string) $entryId, $actorId, $context));
    }
}
