<?php

namespace App\Services\Customer;

use App\Database\Connection;
use App\Models\CustomerVehicle;
use App\Services\Vehicle\VehicleMasterRepository;
use App\Services\Vehicle\VehicleMasterValidator;
use DateTimeImmutable;
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
        $payload = $this->normalizeVehiclePayload($customerId, $data);

        $sql = 'INSERT INTO customer_vehicles (customer_id, vehicle_master_id, year, make, model, engine, transmission, drive, '
            . 'trim, vin, license_plate, notes, mileage_in, mileage_out, is_active, created_at, updated_at) '
            . 'VALUES (:customer_id, :vehicle_master_id, :year, :make, :model, :engine, :transmission, :drive, :trim, '
            . ':vin, :license_plate, :notes, :mileage_in, :mileage_out, :is_active, :created_at, :updated_at)';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($payload);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return (new CustomerVehicle(array_merge($payload, ['id' => $id])))->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateVehicle(int $customerId, int $vehicleId, array $data): array
    {
        $existing = $this->findByCustomer($customerId, $vehicleId);
        $payload = $this->normalizeVehiclePayload($customerId, $data, $existing);

        $sql = 'UPDATE customer_vehicles SET vehicle_master_id = :vehicle_master_id, year = :year, make = :make, '
            . 'model = :model, engine = :engine, transmission = :transmission, drive = :drive, trim = :trim, '
            . 'vin = :vin, license_plate = :license_plate, notes = :notes, mileage_in = :mileage_in, mileage_out = :mileage_out, '
            . 'updated_at = :updated_at WHERE id = :id AND customer_id = :customer_id';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(array_merge($payload, ['id' => $vehicleId]));

        return (new CustomerVehicle(array_merge($payload, ['id' => $vehicleId])))->toArray();
    }

    public function deleteVehicle(int $customerId, int $vehicleId): bool
    {
        $this->findByCustomer($customerId, $vehicleId);

        $sql = 'UPDATE customer_vehicles SET is_active = 0, updated_at = :updated_at WHERE id = :id AND customer_id = :customer_id';
        $stmt = $this->connection->pdo()->prepare($sql);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        return $stmt->execute(['id' => $vehicleId, 'customer_id' => $customerId, 'updated_at' => $now]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listVehicles(int $customerId): array
    {
        $sql = 'SELECT cv.*, history.last_service_date, history.last_service_mileage '
            . 'FROM customer_vehicles cv '
            . 'LEFT JOIN ('
            . 'SELECT i.vehicle_id, MAX(i.created_at) AS last_service_date, MAX(cv2.mileage_out) AS last_service_mileage '
            . 'FROM invoices i '
            . 'INNER JOIN customer_vehicles cv2 ON cv2.id = i.vehicle_id '
            . 'WHERE i.customer_id = :customer_id '
            . 'GROUP BY i.vehicle_id'
            . ') history ON history.vehicle_id = cv.id '
            . 'WHERE cv.customer_id = :customer_id AND cv.is_active = 1 '
            . 'ORDER BY cv.created_at DESC, cv.id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['customer_id' => $customerId]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn ($row) => (new CustomerVehicle($row))->toArray(), $rows);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function searchVehicles(array $filters = []): array
    {
        $clauses = ['cv.is_active = 1'];
        $bindings = [];

        if (!empty($filters['customer_id'])) {
            $clauses[] = 'cv.customer_id = :customer_id';
            $bindings['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['customer_query'])) {
            $clauses[] = '('
                . 'c.first_name LIKE :customer_query OR c.last_name LIKE :customer_query '
                . 'OR CONCAT(c.first_name, " ", c.last_name) LIKE :customer_query '
                . 'OR c.email LIKE :customer_query OR c.phone LIKE :customer_query '
                . 'OR CAST(c.id AS CHAR) LIKE :customer_query'
                . ')';
            $bindings['customer_query'] = '%' . trim((string) $filters['customer_query']) . '%';
        }

        if (!empty($filters['year'])) {
            $clauses[] = 'cv.year = :year';
            $bindings['year'] = (int) $filters['year'];
        }

        foreach (['make', 'model'] as $field) {
            if (!empty($filters[$field])) {
                $clauses[] = "cv.$field LIKE :$field";
                $bindings[$field] = trim((string) $filters[$field]) . '%';
            }
        }

        if (!empty($filters['term'])) {
            $clauses[] = '('
                . 'cv.make LIKE :term OR cv.model LIKE :term OR cv.engine LIKE :term '
                . 'OR cv.transmission LIKE :term OR cv.drive LIKE :term OR cv.trim LIKE :term '
                . 'OR cv.vin LIKE :term OR cv.license_plate LIKE :term'
                . ')';
            $bindings['term'] = '%' . trim((string) $filters['term']) . '%';
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        $sql = 'SELECT cv.* FROM customer_vehicles cv '
            . 'LEFT JOIN customers c ON c.id = cv.customer_id '
            . $where
            . ' ORDER BY cv.created_at DESC, cv.id DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($bindings);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn ($row) => (new CustomerVehicle($row))->toArray(), $rows);
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function normalizeVehiclePayload(int $customerId, array $data, ?array $existing = null): array
    {
        $vehicleMasterId = isset($data['vehicle_master_id']) ? (int) $data['vehicle_master_id'] : ($existing['vehicle_master_id'] ?? null);
        $master = null;

        if ($vehicleMasterId !== null) {
            $master = $this->vehicleMasterRepository->find($vehicleMasterId);
            if ($master === null) {
                throw new \InvalidArgumentException('Selected master vehicle not found.');
            }
        }

        $year = (int) ($data['year'] ?? $existing['year'] ?? $master?->year ?? 0);
        $make = trim((string) ($data['make'] ?? $existing['make'] ?? $master?->make ?? ''));
        $model = trim((string) ($data['model'] ?? $existing['model'] ?? $master?->model ?? ''));
        $engine = trim((string) ($data['engine'] ?? $existing['engine'] ?? $master?->engine ?? ''));
        $transmission = trim((string) ($data['transmission'] ?? $existing['transmission'] ?? $master?->transmission ?? ''));
        $drive = trim((string) ($data['drive'] ?? $existing['drive'] ?? $master?->drive ?? ''));
        $trim = $data['trim'] ?? $existing['trim'] ?? $master?->trim ?? null;

        foreach ([
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'engine' => $engine,
            'transmission' => $transmission,
            'drive' => $drive,
        ] as $field => $value) {
            if ($value === '' || $value === null) {
                throw new \InvalidArgumentException(sprintf('Field %s is required to save a vehicle.', $field));
            }
        }

        if ($year <= 0) {
            throw new \InvalidArgumentException('Year must be a positive number.');
        }

        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return [
            'customer_id' => $customerId,
            'vehicle_master_id' => $vehicleMasterId,
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'engine' => $engine,
            'transmission' => $transmission,
            'drive' => $drive,
            'trim' => $trim !== null ? trim((string) $trim) : null,
            'vin' => isset($data['vin']) ? trim((string) $data['vin']) : ($existing['vin'] ?? null),
            'license_plate' => isset($data['license_plate']) ? trim((string) $data['license_plate']) : ($existing['license_plate'] ?? null),
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : ($existing['notes'] ?? null),
            'mileage_in' => isset($data['mileage_in']) ? (int) $data['mileage_in'] : ($existing['mileage_in'] ?? null),
            'mileage_out' => isset($data['mileage_out']) ? (int) $data['mileage_out'] : ($existing['mileage_out'] ?? null),
            'is_active' => isset($data['is_active']) ? (int) $data['is_active'] : ($existing['is_active'] ?? 1),
            'created_at' => $existing['created_at'] ?? $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function findByCustomer(int $customerId, int $vehicleId): array
    {
        $sql = 'SELECT * FROM customer_vehicles WHERE id = :id AND customer_id = :customer_id AND is_active = 1 LIMIT 1';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['id' => $vehicleId, 'customer_id' => $customerId]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \InvalidArgumentException('Vehicle not found for this customer.');
        }

        return $row;
    }
}
