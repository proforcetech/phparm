<?php

namespace App\Services\Appointment;

use App\Database\Connection;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;
use InvalidArgumentException;

class AvailabilityService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public function getAvailableSlots(string $date, ?int $technicianId = null): array
    {
        $day = DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($day === false) {
            throw new InvalidArgumentException('Invalid date');
        }

        $holiday = $this->fetchHoliday($day);
        if ($holiday !== null) {
            return [
                'slots' => [],
                'closed' => true,
                'reason' => $holiday['label'] ?? 'Closed for holiday',
            ];
        }

        $config = $this->fetchDayConfig((int) $day->format('w'));
        if ($config === null) {
            $config = [
                'opens_at' => '08:00',
                'closes_at' => '17:00',
                'slot_minutes' => 30,
                'buffer_minutes' => 0,
                'is_closed' => 0,
            ];
        }

        if ((bool) $config['is_closed']) {
            return [
                'slots' => [],
                'closed' => true,
                'reason' => $config['label'] ?? 'Closed',
            ];
        }

        $slots = $this->generateSlots(
            $day,
            $config['opens_at'] ?? '08:00',
            $config['closes_at'] ?? '17:00',
            (int) ($config['slot_minutes'] ?? 30),
            (int) ($config['buffer_minutes'] ?? 0),
            $technicianId
        );

        return [
            'slots' => $slots,
            'closed' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'hours' => $this->fetchWeeklyHours(),
            'holidays' => $this->fetchHolidays(),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    public function saveConfig(array $config): void
    {
        $hours = $config['hours'] ?? [];
        $holidays = $config['holidays'] ?? [];

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM availability_settings');

        $insert = $pdo->prepare(
            'INSERT INTO availability_settings (day_of_week, holiday_date, label, opens_at, closes_at, slot_minutes, buffer_minutes, is_closed) ' .
            'VALUES (:day_of_week, :holiday_date, :label, :opens_at, :closes_at, :slot_minutes, :buffer_minutes, :is_closed)'
        );

        foreach ($hours as $row) {
            $insert->execute([
                'day_of_week' => $row['day_of_week'] ?? null,
                'holiday_date' => null,
                'label' => $row['label'] ?? null,
                'opens_at' => $row['opens_at'] ?? null,
                'closes_at' => $row['closes_at'] ?? null,
                'slot_minutes' => $row['slot_minutes'] ?? 30,
                'buffer_minutes' => $row['buffer_minutes'] ?? 0,
                'is_closed' => !empty($row['is_closed']) ? 1 : 0,
            ]);
        }

        foreach ($holidays as $row) {
            $insert->execute([
                'day_of_week' => null,
                'holiday_date' => $row['holiday_date'] ?? null,
                'label' => $row['label'] ?? null,
                'opens_at' => null,
                'closes_at' => null,
                'slot_minutes' => 0,
                'buffer_minutes' => 0,
                'is_closed' => 1,
            ]);
        }

        $pdo->commit();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchWeeklyHours(): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM availability_settings WHERE holiday_date IS NULL ORDER BY day_of_week ASC');
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchHolidays(): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM availability_settings WHERE holiday_date IS NOT NULL ORDER BY holiday_date ASC');
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchHoliday(DateTimeImmutable $date): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM availability_settings WHERE holiday_date = :date LIMIT 1');
        $stmt->execute(['date' => $date->format('Y-m-d')]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @param int $dayOfWeek 0 (for Sunday) through 6 (for Saturday)
     * @return array<string, mixed>|null
     */
    private function fetchDayConfig(int $dayOfWeek): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM availability_settings WHERE day_of_week = :day LIMIT 1');
        $stmt->execute(['day' => $dayOfWeek]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function generateSlots(
        DateTimeImmutable $date,
        string $opensAt,
        string $closesAt,
        int $slotMinutes,
        int $bufferMinutes,
        ?int $technicianId
    ): array {
        $slotMinutes = max(5, $slotMinutes);
        $bufferMinutes = max(0, $bufferMinutes);
        $start = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $opensAt);
        $end = new DateTimeImmutable($date->format('Y-m-d') . ' ' . $closesAt);
        $interval = new DateInterval('PT' . $slotMinutes . 'M');
        $slots = [];

        if ($end <= $start) {
            return $slots;
        }

        for ($current = $start; $current < $end; $current = $current->add($interval)) {
            $slotEnd = $current->add($interval);
            $bufferedEnd = $slotEnd->add(new DateInterval('PT' . $bufferMinutes . 'M'));
            if ($bufferedEnd > $end) {
                break;
            }

            $slots[] = [
                'start' => $current->format(DateTimeInterface::ATOM),
                'end' => $bufferedEnd->format(DateTimeInterface::ATOM),
                'available' => $this->isAvailable($current, $bufferedEnd, $technicianId),
            ];
        }

        return $slots;
    }

    private function isAvailable(DateTimeImmutable $start, DateTimeImmutable $end, ?int $technicianId): bool
    {
        $query = 'SELECT COUNT(*) FROM appointments WHERE start_time < :end AND end_time > :start AND status NOT IN ("cancelled")';
        $params = [
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ];

        if ($technicianId !== null) {
            $query .= ' AND (technician_id IS NULL OR technician_id = :technician_id)';
            $params['technician_id'] = $technicianId;
        }

        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === 0;
    }
}
