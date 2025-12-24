<?php

namespace App\Services\Appointment;

use App\Database\Connection;
use App\Models\Appointment;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Webhooks\WebhookDispatcher;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class AppointmentService
{
    private Connection $connection;
    private ?AuditLogger $audit;
    private ?WebhookDispatcher $webhooks;
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(Connection $connection, ?AuditLogger $audit = null, ?WebhookDispatcher $webhooks = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
        $this->webhooks = $webhooks;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $actorId): Appointment
    {
        $required = ['start_time', 'end_time', 'status'];
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
            'customer_id' => $payload['customer_id'] ?? null,
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
        $this->notify('appointment.created', $appointment);

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
            'UPDATE appointments SET status = :status, technician_id = :technician_id, start_time = :start_time, end_time = :end_time, notes = :notes, updated_at = NOW() WHERE id = :id'
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
        $this->notify('appointment.updated', $updated, ['before' => $existing->toArray(), 'actor_id' => $actorId]);

        return $updated;
    }

    public function findById(int $appointmentId): ?Appointment
    {
        return $this->fetch($appointmentId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, Appointment>
     */
    public function list(array $filters = []): array
    {
        $sql = 'SELECT * FROM appointments WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['customer_id'])) {
            $sql .= ' AND customer_id = :customer_id';
            $params['customer_id'] = $filters['customer_id'];
        }

        if (!empty($filters['technician_id'])) {
            $sql .= ' AND technician_id = :technician_id';
            $params['technician_id'] = $filters['technician_id'];
        }

        if (!empty($filters['date'])) {
            $sql .= ' AND DATE(start_time) = :start_date';
            $params['start_date'] = $filters['date'];
        }

        $sql .= ' ORDER BY start_time DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn($row) => new Appointment($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
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

        $stmt = $this->connection->pdo()->prepare('UPDATE appointments SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $appointmentId]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->log('appointment.status_changed', $appointmentId, $actorId, ['status' => $status]);
            $current = $this->fetch($appointmentId);
            $this->notify('appointment.status_changed', $current, ['status' => $status, 'actor_id' => $actorId]);
        }

        return $updated;
    }

    public function updateStatus(int $appointmentId, string $status, int $actorId): ?Appointment
    {
        $updated = $this->transitionStatus($appointmentId, $status, $actorId);

        return $updated ? $this->fetch($appointmentId) : null;
    }

    public function delete(int $appointmentId, int $actorId): bool
    {
        $appointment = $this->fetch($appointmentId);
        $stmt = $this->connection->pdo()->prepare('DELETE FROM appointments WHERE id = :id');
        $stmt->execute(['id' => $appointmentId]);

        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->log('appointment.deleted', $appointmentId, $actorId, ['appointment' => $appointment?->toArray()]);
            $this->notify('appointment.deleted', $appointment, ['actor_id' => $actorId]);
        }

        return $deleted;
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

    /**
     * @param array<string, mixed> $context
     */
    private function notify(string $event, ?Appointment $appointment, array $context = []): void
    {
        if ($appointment === null || $this->webhooks === null) {
            return;
        }

        $payload = array_merge([
            'appointment' => [
                'id' => $appointment->id,
                'status' => $appointment->status,
                'start_time' => $appointment->start_time,
                'end_time' => $appointment->end_time,
                'estimate_id' => $appointment->estimate_id,
                'technician_id' => $appointment->technician_id,
                'notes' => $appointment->notes,
            ],
            'customer' => $this->fetchCustomer($appointment->customer_id),
            'vehicle' => $this->fetchVehicle($appointment->vehicle_id),
        ], $context);

        $this->webhooks->dispatch($event, $payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCustomer(?int $customerId): ?array
    {
        if ($customerId === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT id, first_name, last_name, email, phone, external_reference FROM customers WHERE id = :id');
        $stmt->execute(['id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchVehicle(?int $vehicleId): ?array
    {
        if ($vehicleId === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT id, customer_id, year, make, model, engine, transmission, drive, trim, vin, license_plate FROM customer_vehicles WHERE id = :id');
        $stmt->execute(['id' => $vehicleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
