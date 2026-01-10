<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class DriverShiftService
{
    private Connection $connection;
    private TruckChecklistService $checklists;

    public function __construct(Connection $connection, TruckChecklistService $checklists)
    {
        $this->connection = $connection;
        $this->checklists = $checklists;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveShift(int $driverProfileId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM driver_shifts WHERE driver_profile_id = :driver_profile_id AND status = :status ORDER BY shift_start DESC LIMIT 1'
        );
        $stmt->execute([
            'driver_profile_id' => $driverProfileId,
            'status' => 'active',
        ]);

        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        return $shift ?: null;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function startShift(int $driverProfileId, array $payload, int $actorId): array
    {
        if ($this->getActiveShift($driverProfileId) !== null) {
            throw new InvalidArgumentException('Driver already has an active shift.');
        }

        $shiftEnd = $this->parseShiftEnd($payload['shift_end'] ?? null);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        $shiftId = 0;

        try {
            $checklist = $this->resolveChecklistEntry(
                $driverProfileId,
                'pre_trip',
                $payload,
                null,
                $actorId
            );

            $stmt = $pdo->prepare(
                'INSERT INTO driver_shifts (driver_profile_id, shift_start, shift_end, minutes_worked, status, pre_trip_checklist_id, created_at, updated_at)
                 VALUES (:driver_profile_id, NOW(), :shift_end, 0, :status, :pre_trip_checklist_id, NOW(), NOW())'
            );
            $stmt->execute([
                'driver_profile_id' => $driverProfileId,
                'shift_end' => $shiftEnd->format('Y-m-d H:i:s'),
                'status' => 'active',
                'pre_trip_checklist_id' => $checklist['id'],
            ]);

            $shiftId = (int) $pdo->lastInsertId();

            $pdo->prepare('UPDATE truck_checklist_entries SET driver_shift_id = :shift_id WHERE id = :id')->execute([
                'shift_id' => $shiftId,
                'id' => $checklist['id'],
            ]);

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->getShift($shiftId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function endShift(int $driverProfileId, int $shiftId, array $payload, int $actorId): array
    {
        $shift = $this->getShiftForDriver($driverProfileId, $shiftId);
        if ($shift === null) {
            throw new InvalidArgumentException('Shift not found.');
        }

        if (($shift['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Shift is not active.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $checklist = $this->resolveChecklistEntry(
                $driverProfileId,
                'post_trip',
                $payload,
                (int) $shift['id'],
                $actorId
            );

            $shiftStart = new DateTimeImmutable($shift['shift_start']);
            $now = new DateTimeImmutable('now');
            $minutesWorked = max(0, (int) round(($now->getTimestamp() - $shiftStart->getTimestamp()) / 60));

            $stmt = $pdo->prepare(
                'UPDATE driver_shifts
                 SET shift_end = :shift_end,
                     minutes_worked = :minutes_worked,
                     status = :status,
                     post_trip_checklist_id = :post_trip_checklist_id,
                     updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'shift_end' => $now->format('Y-m-d H:i:s'),
                'minutes_worked' => $minutesWorked,
                'status' => 'completed',
                'post_trip_checklist_id' => $checklist['id'],
                'id' => $shiftId,
            ]);

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        return $this->getShift($shiftId);
    }

    /**
     * @return array<string, mixed>
     */
    public function getShift(int $shiftId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM driver_shifts WHERE id = :id');
        $stmt->execute(['id' => $shiftId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$shift) {
            throw new InvalidArgumentException('Shift not found.');
        }

        return $shift;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getShiftForDriver(int $driverProfileId, int $shiftId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM driver_shifts WHERE id = :id AND driver_profile_id = :driver_profile_id'
        );
        $stmt->execute([
            'id' => $shiftId,
            'driver_profile_id' => $driverProfileId,
        ]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);

        return $shift ?: null;
    }

    private function parseShiftEnd(?string $shiftEnd): DateTimeImmutable
    {
        if ($shiftEnd === null || $shiftEnd === '') {
            return (new DateTimeImmutable('now'))->add(new DateInterval('PT8H'));
        }

        try {
            return new DateTimeImmutable($shiftEnd);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid shift_end value.');
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveChecklistEntry(
        int $driverProfileId,
        string $type,
        array $payload,
        ?int $shiftId,
        int $actorId
    ): array {
        if (!empty($payload['checklist_entry_id'])) {
            $entry = $this->checklists->getEntry((int) $payload['checklist_entry_id']);
            if ($entry === null || (int) $entry['driver_profile_id'] !== $driverProfileId) {
                throw new InvalidArgumentException('Checklist entry not found.');
            }
            if ($entry['checklist_type'] !== $type) {
                throw new InvalidArgumentException('Checklist entry type mismatch.');
            }

            return $entry;
        }

        $templateId = isset($payload['template_id']) ? (int) $payload['template_id'] : 0;
        if ($templateId <= 0) {
            throw new InvalidArgumentException('Template ID is required.');
        }

        if (empty($payload['items']) || !is_array($payload['items'])) {
            throw new InvalidArgumentException('Checklist items are required.');
        }

        return $this->checklists->createEntry(
            $driverProfileId,
            $templateId,
            $type,
            $payload['items'],
            $payload['notes'] ?? null,
            $shiftId,
            $actorId
        );
    }
}
