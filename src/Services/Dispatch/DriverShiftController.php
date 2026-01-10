<?php

namespace App\Services\Dispatch;

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class DriverShiftController
{
    private DriverShiftService $service;
    private AccessGate $gate;
    private Connection $connection;

    public function __construct(DriverShiftService $service, AccessGate $gate, Connection $connection)
    {
        $this->service = $service;
        $this->gate = $gate;
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public function active(User $user): array
    {
        $this->gate->assert($user, 'driver_shifts.view');

        $driverProfileId = $this->resolveDriverProfileId($user->id);
        if ($driverProfileId === null) {
            return ['data' => null];
        }

        return [
            'data' => $this->service->getActiveShift($driverProfileId),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function start(User $user, array $payload): array
    {
        $this->gate->assert($user, 'driver_shifts.start');

        $driverProfileId = $this->resolveDriverProfileId($user->id);
        if ($driverProfileId === null) {
            throw new InvalidArgumentException('Driver profile not found.');
        }

        return $this->service->startShift($driverProfileId, $payload, $user->id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function end(User $user, int $shiftId, array $payload): array
    {
        $this->gate->assert($user, 'driver_shifts.end');

        $driverProfileId = $this->resolveDriverProfileId($user->id);
        if ($driverProfileId === null) {
            throw new InvalidArgumentException('Driver profile not found.');
        }

        return $this->service->endShift($driverProfileId, $shiftId, $payload, $user->id);
    }

    private function resolveDriverProfileId(int $userId): ?int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM driver_profiles WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }
}
