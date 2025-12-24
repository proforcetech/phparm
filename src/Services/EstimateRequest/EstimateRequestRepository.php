<?php

namespace App\Services\EstimateRequest;

use App\Database\Connection;
use App\Models\EstimateRequest;
use PDO;

class EstimateRequestRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function find(int $id): ?EstimateRequest
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_requests WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapEstimateRequest($row) : null;
    }

    /**
     * Create a new estimate request
     *
     * @param array<string, mixed> $data
     * @return EstimateRequest
     */
    public function create(array $data): EstimateRequest
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_requests (
                name, email, phone,
                address, city, state, zip,
                service_address_same_as_customer, service_address, service_city, service_state, service_zip,
                vehicle_year, vehicle_make, vehicle_model, vin, license_plate,
                service_type_id, service_type_name, description,
                source, ip_address, user_agent,
                created_at, updated_at
            ) VALUES (
                :name, :email, :phone,
                :address, :city, :state, :zip,
                :service_address_same_as_customer, :service_address, :service_city, :service_state, :service_zip,
                :vehicle_year, :vehicle_make, :vehicle_model, :vin, :license_plate,
                :service_type_id, :service_type_name, :description,
                :source, :ip_address, :user_agent,
                NOW(), NOW()
            )
        SQL);

        $stmt->execute([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'address' => $data['address'],
            'city' => $data['city'],
            'state' => $data['state'],
            'zip' => $data['zip'],
            'service_address_same_as_customer' => $data['service_address_same_as_customer'] ? 1 : 0,
            'service_address' => $data['service_address'] ?? null,
            'service_city' => $data['service_city'] ?? null,
            'service_state' => $data['service_state'] ?? null,
            'service_zip' => $data['service_zip'] ?? null,
            'vehicle_year' => $data['vehicle_year'] ?? null,
            'vehicle_make' => $data['vehicle_make'] ?? null,
            'vehicle_model' => $data['vehicle_model'] ?? null,
            'vin' => $data['vin'] ?? null,
            'license_plate' => $data['license_plate'] ?? null,
            'service_type_id' => $data['service_type_id'] ?? null,
            'service_type_name' => $data['service_type_name'] ?? null,
            'description' => $data['description'] ?? null,
            'source' => $data['source'] ?? 'website',
            'ip_address' => $data['ip_address'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id);
    }

    /**
     * Update estimate request status
     *
     * @param int $id
     * @param string $status
     * @param int|null $userId Staff user ID
     * @return EstimateRequest|null
     */
    public function updateStatus(int $id, string $status, ?int $userId = null): ?EstimateRequest
    {
        $allowedStatuses = ['pending', 'contacted', 'estimated', 'declined', 'converted'];
        if (!in_array($status, $allowedStatuses, true)) {
            throw new \InvalidArgumentException('Invalid status: ' . $status);
        }

        $updates = ['status = :status', 'updated_at = NOW()'];
        $params = ['id' => $id, 'status' => $status];

        if ($status === 'contacted' && $userId !== null) {
            $updates[] = 'contacted_at = NOW()';
            $updates[] = 'contacted_by = :contacted_by';
            $params['contacted_by'] = $userId;
        }

        $sql = 'UPDATE estimate_requests SET ' . implode(', ', $updates) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->find($id);
    }

    /**
     * Link estimate request to created estimate
     *
     * @param int $requestId
     * @param int $estimateId
     * @return EstimateRequest|null
     */
    public function linkToEstimate(int $requestId, int $estimateId): ?EstimateRequest
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE estimate_requests SET estimate_id = :estimate_id, status = :status, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $requestId,
            'estimate_id' => $estimateId,
            'status' => 'estimated',
        ]);

        return $this->find($requestId);
    }

    /**
     * Link estimate request to customer and/or vehicle
     *
     * @param int $requestId
     * @param int|null $customerId
     * @param int|null $vehicleId
     * @return EstimateRequest|null
     */
    public function linkToCustomerAndVehicle(int $requestId, ?int $customerId = null, ?int $vehicleId = null): ?EstimateRequest
    {
        $updates = ['updated_at = NOW()'];
        $params = ['id' => $requestId];

        if ($customerId !== null) {
            $updates[] = 'customer_id = :customer_id';
            $params['customer_id'] = $customerId;
        }

        if ($vehicleId !== null) {
            $updates[] = 'vehicle_id = :vehicle_id';
            $params['vehicle_id'] = $vehicleId;
        }

        if (count($updates) > 1) {
            $sql = 'UPDATE estimate_requests SET ' . implode(', ', $updates) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }

        return $this->find($requestId);
    }

    /**
     * Add media to estimate request
     *
     * @param int $requestId
     * @param string $filePath
     * @param string $fileName
     * @param string $mimeType
     * @param int $fileSize
     * @return int Media ID
     */
    public function addMedia(int $requestId, string $filePath, string $fileName, string $mimeType, int $fileSize): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO estimate_request_media (request_id, file_path, file_name, mime_type, file_size, created_at)
             VALUES (:request_id, :file_path, :file_name, :mime_type, :file_size, NOW())'
        );
        $stmt->execute([
            'request_id' => $requestId,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * Get media files for an estimate request
     *
     * @param int $requestId
     * @return array<int, array<string, mixed>>
     */
    public function getMedia(int $requestId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM estimate_request_media WHERE request_id = :request_id ORDER BY created_at ASC'
        );
        $stmt->execute(['request_id' => $requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function mapEstimateRequest(array $row): EstimateRequest
    {
        return new EstimateRequest([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'email' => (string) $row['email'],
            'phone' => (string) $row['phone'],
            'address' => (string) $row['address'],
            'city' => (string) $row['city'],
            'state' => (string) $row['state'],
            'zip' => (string) $row['zip'],
            'service_address_same_as_customer' => (bool) ($row['service_address_same_as_customer'] ?? true),
            'service_address' => $row['service_address'],
            'service_city' => $row['service_city'],
            'service_state' => $row['service_state'],
            'service_zip' => $row['service_zip'],
            'vehicle_year' => $row['vehicle_year'] !== null ? (int) $row['vehicle_year'] : null,
            'vehicle_make' => $row['vehicle_make'],
            'vehicle_model' => $row['vehicle_model'],
            'vin' => $row['vin'],
            'license_plate' => $row['license_plate'],
            'service_type_id' => $row['service_type_id'] !== null ? (int) $row['service_type_id'] : null,
            'service_type_name' => $row['service_type_name'],
            'description' => $row['description'],
            'status' => (string) $row['status'],
            'estimate_id' => $row['estimate_id'] !== null ? (int) $row['estimate_id'] : null,
            'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'vehicle_id' => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            'source' => (string) $row['source'],
            'ip_address' => $row['ip_address'],
            'user_agent' => $row['user_agent'],
            'internal_notes' => $row['internal_notes'],
            'contacted_at' => $row['contacted_at'],
            'contacted_by' => $row['contacted_by'] !== null ? (int) $row['contacted_by'] : null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ]);
    }
}
