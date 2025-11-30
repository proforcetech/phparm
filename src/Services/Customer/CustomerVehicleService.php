<?php

namespace App\Services\Customer;

use App\Database\Connection;
use App\Models\CustomerVehicle;
use App\Services\Vehicle\VehicleMasterRepository;
use App\Services\Vehicle\VehicleMasterValidator;
use PDO;

class CustomerVehicleService
{
    private Connection $connection;
    private VehicleMasterRepository $vehicleMasterRepository;

    public function __construct(Connection $connection, ?VehicleMasterRepository $vehicleMasterRepository = null)
    {
        $this->connection = $connection;
        $this->vehicleMasterRepository = $vehicleMasterRepository ?? new VehicleMasterRepository($this->connection, new VehicleMasterValidator());
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function attachVehicle(int $customerId, array $data): array
    {
        $payload = [
            'customer_id' => $customerId,
            'vehicle_master_id' => isset($data['vehicle_master_id']) ? (int) $data['vehicle_master_id'] : null,
            'vin' => isset($data['vin']) ? trim((string) $data['vin']) : null,
            'plate' => isset($data['plate']) ? trim((string) $data['plate']) : null,
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
        ];

        if ($payload['vehicle_master_id'] === null && empty($payload['vin']) && empty($payload['plate'])) {
            throw new \InvalidArgumentException('Vehicle link requires a master vehicle, VIN, or plate.');
        }

        if ($payload['vehicle_master_id'] !== null) {
            $vehicle = $this->vehicleMasterRepository->find($payload['vehicle_master_id']);
            if ($vehicle === null) {
                throw new \InvalidArgumentException('Selected master vehicle not found.');
            }
        }

        $sql = 'INSERT INTO customer_vehicles (customer_id, vehicle_master_id, vin, plate, notes) '
            . 'VALUES (:customer_id, :vehicle_master_id, :vin, :plate, :notes)';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'customer_id' => $payload['customer_id'],
            'vehicle_master_id' => $payload['vehicle_master_id'],
            'vin' => $payload['vin'],
            'plate' => $payload['plate'],
            'notes' => $payload['notes'],
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return (new CustomerVehicle(array_merge($payload, ['id' => $id])))->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listVehicles(int $customerId): array
    {
        $sql = 'SELECT * FROM customer_vehicles WHERE customer_id = :customer_id ORDER BY id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['customer_id' => $customerId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn ($row) => (new CustomerVehicle($row))->toArray(), $rows);
    }
}
