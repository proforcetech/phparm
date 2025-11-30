<?php

namespace App\Services\Appointment;

use App\Database\Connection;
use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use PDO;

class AvailabilityService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public function generateSlots(DateTimeImmutable $date, int $slotMinutes = 30): array
    {
        $config = $this->fetchConfig();
        $start = new DateTimeImmutable($date->format('Y-m-d') . ' ' . ($config['start'] ?? '08:00'));
        $end = new DateTimeImmutable($date->format('Y-m-d') . ' ' . ($config['end'] ?? '17:00'));
        $buffer = (int) ($config['buffer'] ?? 0);
        $interval = new DateInterval('PT' . $slotMinutes . 'M');
        $slots = [];

        for ($current = $start; $current < $end; $current = $current->add($interval)) {
            $slotEnd = $current->add($interval)->add(new DateInterval('PT' . $buffer . 'M'));
            if ($slotEnd > $end) {
                break;
            }

            $slots[] = [
                'start' => $current->format(DateTimeInterface::ATOM),
                'end' => $slotEnd->format(DateTimeInterface::ATOM),
                'available' => $this->isAvailable($current, $slotEnd),
            ];
        }

        return $slots;
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchConfig(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT setting_key, setting_value FROM availability_settings');
        $config = ['start' => '08:00', 'end' => '17:00', 'slot' => 30, 'buffer' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $config[$row['setting_key']] = $row['setting_value'];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function saveConfig(array $config): void
    {
        $pdo = $this->connection->pdo();
        foreach ($config as $key => $value) {
            $stmt = $pdo->prepare('REPLACE INTO availability_settings (setting_key, setting_value) VALUES (:key, :value)');
            $stmt->execute(['key' => $key, 'value' => $value]);
        }
    }

    private function isAvailable(DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM appointments WHERE start_time < :end AND end_time > :start AND status NOT IN ("cancelled")'
        );
        $stmt->execute([
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ]);

        return (int) $stmt->fetchColumn() === 0;
    }
}
