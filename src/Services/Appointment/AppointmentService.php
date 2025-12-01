<?php

namespace App\Services\Appointment;

use App\Database\Connection;
use App\Models\Appointment;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class AppointmentService
{
    private Connection $connection;
    private ?AuditLogger $audit;
    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $actorId): Appointment
    {
        $required = ['customer_id', 'start_time', 'end_time', 'status'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                throw new InvalidArgumentException("Missing {$field}");
            }
        }

        $start = new DateTimeImmutable($payload['start_time']);
        $end = new DateTimeImmutable($payload['end_time']);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO appointments (customer_id, vehicle_id, technician_id, status, start_time, end_time, estimate_id, notes) ' .
            'VALUES (:customer_id, :vehicle_id, :technician_id, :status, :start_time, :end_time, :estimate_id, :notes)'
        );
        $stmt->execute([
            'customer_id' => $payload['customer_id'],
            'vehicle_id' => $payload['vehicle_id'] ?? null,
            'technician_id' => $payload['technician_id'] ?? null,
            'status' => $payload['status'],
            'start_time' => $start->format('Y-m-d H:i:s'),
            'end_time' => $end->format('Y-m-d H:i:s'),
            'estimate_id' => $payload['estimate_id'] ?? null,
            'notes' => $payload['notes'] ?? null,
        ]);

        $appointmentId = (int) $this->connection->pdo()->lastInsertId();
        $appointment = $this->fetch($appointmentId);
        $this->log('appointment.created', $appointmentId, $actorId, $payload);

        return $appointment ?? new Appointment(['id' => $appointmentId]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $appointmentId, array $payload, int $actorId): ?Appointment
    {
        $existing = $this->fetch($appointmentId);
        if ($existing === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE appointments SET status = :status, technician_id = :technician_id, start_time = :start_time, end_time = :end_time, notes = :notes WHERE id = :id'
        );
        $stmt->execute([
            'status' => $payload['status'] ?? $existing->status,
            'technician_id' => $payload['technician_id'] ?? $existing->technician_id,
            'start_time' => isset($payload['start_time']) ? (new DateTimeImmutable($payload['start_time']))->format('Y-m-d H:i:s') : $existing->start_time,
            'end_time' => isset($payload['end_time']) ? (new DateTimeImmutable($payload['end_time']))->format('Y-m-d H:i:s') : $existing->end_time,
            'notes' => $payload['notes'] ?? $existing->notes,
            'id' => $appointmentId,
        ]);

        $updated = $this->fetch($appointmentId);
        $this->log('appointment.updated', $appointmentId, $actorId, ['before' => $existing->toArray(), 'after' => $updated?->toArray()]);

        return $updated;
    }

    public function listForCustomer(int $customerId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM appointments WHERE customer_id = :customer_id ORDER BY start_time DESC');
        $stmt->execute(['customer_id' => $customerId]);

        return array_map(static fn($row) => new Appointment($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function transitionStatus(int $appointmentId, string $status, int $actorId): bool
    {
        $allowed = ['scheduled', 'confirmed', 'in_progress', 'completed', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid status');
        }

        $stmt = $this->connection->pdo()->prepare('UPDATE appointments SET status = :status WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $appointmentId]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->log('appointment.status_changed', $appointmentId, $actorId, ['status' => $status]);
        }

        return $updated;
    }

    private function fetch(int $appointmentId): ?Appointment
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM appointments WHERE id = :id');
        $stmt->execute(['id' => $appointmentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new Appointment($row) : null;
    }

    private function log(string $action, int $entityId, int $actorId, array $payload = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($action, 'appointment', $entityId, $actorId, $payload));
    }
}
