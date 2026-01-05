<?php

namespace App\Services\Towing;

use App\Database\Connection;
use App\Models\TowingPriceMatrix;
use App\Models\TowingServiceClass;
use App\Models\TowingServiceType;
use InvalidArgumentException;
use PDO;
use Throwable;

class TowingPricingService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    // ===== Service Classes =====

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listServiceClasses(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM towing_service_classes';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        $stmt = $this->connection->pdo()->query($sql);
        return array_map(function (array $row): array {
            return $this->hydrateServiceClass($row)->toArray();
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findServiceClass(int $id): ?TowingServiceClass
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM towing_service_classes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateServiceClass($row) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createServiceClass(array $payload): TowingServiceClass
    {
        if (empty($payload['name'])) {
            throw new InvalidArgumentException('Service class name is required');
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO towing_service_classes (name, description, weight_min, weight_max, is_active, sort_order)
             VALUES (:name, :description, :weight_min, :weight_max, :is_active, :sort_order)'
        );
        $stmt->execute([
            'name' => $payload['name'],
            'description' => $payload['description'] ?? null,
            'weight_min' => $payload['weight_min'] ?? null,
            'weight_max' => $payload['weight_max'] ?? null,
            'is_active' => isset($payload['is_active']) ? (int) (bool) $payload['is_active'] : 1,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return $this->findServiceClass((int) $pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateServiceClass(int $id, array $payload): ?TowingServiceClass
    {
        $existing = $this->findServiceClass($id);
        if (!$existing) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE towing_service_classes SET
                name = COALESCE(:name, name),
                description = COALESCE(:description, description),
                weight_min = COALESCE(:weight_min, weight_min),
                weight_max = COALESCE(:weight_max, weight_max),
                is_active = COALESCE(:is_active, is_active),
                sort_order = COALESCE(:sort_order, sort_order)
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $payload['name'] ?? null,
            'description' => $payload['description'] ?? null,
            'weight_min' => $payload['weight_min'] ?? null,
            'weight_max' => $payload['weight_max'] ?? null,
            'is_active' => array_key_exists('is_active', $payload) ? (int) (bool) $payload['is_active'] : null,
            'sort_order' => $payload['sort_order'] ?? null,
        ]);

        return $this->findServiceClass($id);
    }

    public function deleteServiceClass(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM towing_service_classes WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function hydrateServiceClass(array $row): TowingServiceClass
    {
        $class = new TowingServiceClass($row);
        $class->is_active = (bool) $row['is_active'];
        $class->sort_order = (int) $row['sort_order'];
        return $class;
    }

    // ===== Service Types =====

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listServiceTypes(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM towing_service_types';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC';

        $stmt = $this->connection->pdo()->query($sql);
        return array_map(function (array $row): array {
            return $this->hydrateServiceType($row)->toArray();
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findServiceType(int $id): ?TowingServiceType
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM towing_service_types WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydrateServiceType($row) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createServiceType(array $payload): TowingServiceType
    {
        if (empty($payload['name'])) {
            throw new InvalidArgumentException('Service type name is required');
        }
        if (empty($payload['code'])) {
            throw new InvalidArgumentException('Service type code is required');
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO towing_service_types (name, code, description, is_active, sort_order)
             VALUES (:name, :code, :description, :is_active, :sort_order)'
        );
        $stmt->execute([
            'name' => $payload['name'],
            'code' => strtoupper($payload['code']),
            'description' => $payload['description'] ?? null,
            'is_active' => isset($payload['is_active']) ? (int) (bool) $payload['is_active'] : 1,
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);

        return $this->findServiceType((int) $pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateServiceType(int $id, array $payload): ?TowingServiceType
    {
        $existing = $this->findServiceType($id);
        if (!$existing) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE towing_service_types SET
                name = COALESCE(:name, name),
                code = COALESCE(:code, code),
                description = COALESCE(:description, description),
                is_active = COALESCE(:is_active, is_active),
                sort_order = COALESCE(:sort_order, sort_order)
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'name' => $payload['name'] ?? null,
            'code' => isset($payload['code']) ? strtoupper($payload['code']) : null,
            'description' => $payload['description'] ?? null,
            'is_active' => array_key_exists('is_active', $payload) ? (int) (bool) $payload['is_active'] : null,
            'sort_order' => $payload['sort_order'] ?? null,
        ]);

        return $this->findServiceType($id);
    }

    public function deleteServiceType(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM towing_service_types WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function hydrateServiceType(array $row): TowingServiceType
    {
        $type = new TowingServiceType($row);
        $type->is_active = (bool) $row['is_active'];
        $type->sort_order = (int) $row['sort_order'];
        return $type;
    }

    // ===== Price Matrix =====

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPriceMatrix(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        if (isset($filters['service_class_id'])) {
            $conditions[] = 'pm.service_class_id = :service_class_id';
            $params['service_class_id'] = (int) $filters['service_class_id'];
        }

        if (isset($filters['service_type_id'])) {
            $conditions[] = 'pm.service_type_id = :service_type_id';
            $params['service_type_id'] = (int) $filters['service_type_id'];
        }

        if (isset($filters['is_active'])) {
            $conditions[] = 'pm.is_active = :is_active';
            $params['is_active'] = (int) (bool) $filters['is_active'];
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $sql = "SELECT pm.*, sc.name AS service_class_name, st.name AS service_type_name, st.code AS service_type_code
                FROM towing_price_matrix pm
                JOIN towing_service_classes sc ON pm.service_class_id = sc.id
                JOIN towing_service_types st ON pm.service_type_id = st.id
                {$where}
                ORDER BY sc.sort_order ASC, st.sort_order ASC";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(function (array $row): array {
            return $this->hydratePriceMatrix($row)->toArray();
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get the full matrix as a structured grid for display
     * @return array{classes: array, types: array, matrix: array}
     */
    public function getFullMatrix(): array
    {
        $classes = $this->listServiceClasses(true);
        $types = $this->listServiceTypes(true);
        $prices = $this->listPriceMatrix(['is_active' => true]);

        // Build a lookup map: class_id -> type_id -> price entry
        $matrixLookup = [];
        foreach ($prices as $price) {
            $classId = $price['service_class_id'];
            $typeId = $price['service_type_id'];
            if (!isset($matrixLookup[$classId])) {
                $matrixLookup[$classId] = [];
            }
            $matrixLookup[$classId][$typeId] = $price;
        }

        return [
            'classes' => $classes,
            'types' => $types,
            'matrix' => $matrixLookup,
            'all_prices' => $prices,
        ];
    }

    public function findPriceMatrix(int $id): ?TowingPriceMatrix
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT pm.*, sc.name AS service_class_name, st.name AS service_type_name, st.code AS service_type_code
             FROM towing_price_matrix pm
             JOIN towing_service_classes sc ON pm.service_class_id = sc.id
             JOIN towing_service_types st ON pm.service_type_id = st.id
             WHERE pm.id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydratePriceMatrix($row) : null;
    }

    public function findPriceMatrixByClassAndType(int $classId, int $typeId): ?TowingPriceMatrix
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT pm.*, sc.name AS service_class_name, st.name AS service_type_name, st.code AS service_type_code
             FROM towing_price_matrix pm
             JOIN towing_service_classes sc ON pm.service_class_id = sc.id
             JOIN towing_service_types st ON pm.service_type_id = st.id
             WHERE pm.service_class_id = :class_id AND pm.service_type_id = :type_id'
        );
        $stmt->execute(['class_id' => $classId, 'type_id' => $typeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->hydratePriceMatrix($row) : null;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createPriceMatrix(array $payload): TowingPriceMatrix
    {
        if (empty($payload['service_class_id'])) {
            throw new InvalidArgumentException('Service class ID is required');
        }
        if (empty($payload['service_type_id'])) {
            throw new InvalidArgumentException('Service type ID is required');
        }

        // Check for existing entry
        $existing = $this->findPriceMatrixByClassAndType(
            (int) $payload['service_class_id'],
            (int) $payload['service_type_id']
        );
        if ($existing) {
            throw new InvalidArgumentException('Price matrix entry already exists for this service class and type combination');
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO towing_price_matrix (
                service_class_id, service_type_id, rollout_fee, hookup_fee, onhook_fee,
                mileage_rate, deadhead_rate, accident_cleanup_fee, winch_fee,
                storage_daily_rate, after_hours_multiplier, minimum_charge, is_active, notes
            ) VALUES (
                :service_class_id, :service_type_id, :rollout_fee, :hookup_fee, :onhook_fee,
                :mileage_rate, :deadhead_rate, :accident_cleanup_fee, :winch_fee,
                :storage_daily_rate, :after_hours_multiplier, :minimum_charge, :is_active, :notes
            )'
        );
        $stmt->execute($this->buildPriceMatrixParams($payload));

        return $this->findPriceMatrix((int) $pdo->lastInsertId());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePriceMatrix(int $id, array $payload): ?TowingPriceMatrix
    {
        $existing = $this->findPriceMatrix($id);
        if (!$existing) {
            return null;
        }

        // If changing class/type, check for conflicts
        if (isset($payload['service_class_id']) || isset($payload['service_type_id'])) {
            $classId = $payload['service_class_id'] ?? $existing->service_class_id;
            $typeId = $payload['service_type_id'] ?? $existing->service_type_id;
            $conflict = $this->findPriceMatrixByClassAndType((int) $classId, (int) $typeId);
            if ($conflict && $conflict->id !== $id) {
                throw new InvalidArgumentException('Price matrix entry already exists for this service class and type combination');
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE towing_price_matrix SET
                service_class_id = COALESCE(:service_class_id, service_class_id),
                service_type_id = COALESCE(:service_type_id, service_type_id),
                rollout_fee = COALESCE(:rollout_fee, rollout_fee),
                hookup_fee = COALESCE(:hookup_fee, hookup_fee),
                onhook_fee = COALESCE(:onhook_fee, onhook_fee),
                mileage_rate = COALESCE(:mileage_rate, mileage_rate),
                deadhead_rate = COALESCE(:deadhead_rate, deadhead_rate),
                accident_cleanup_fee = COALESCE(:accident_cleanup_fee, accident_cleanup_fee),
                winch_fee = COALESCE(:winch_fee, winch_fee),
                storage_daily_rate = COALESCE(:storage_daily_rate, storage_daily_rate),
                after_hours_multiplier = COALESCE(:after_hours_multiplier, after_hours_multiplier),
                minimum_charge = COALESCE(:minimum_charge, minimum_charge),
                is_active = COALESCE(:is_active, is_active),
                notes = COALESCE(:notes, notes)
             WHERE id = :id'
        );
        $params = $this->buildPriceMatrixParams($payload, true);
        $params['id'] = $id;
        $stmt->execute($params);

        return $this->findPriceMatrix($id);
    }

    /**
     * Update or create a price matrix entry (upsert)
     * @param array<string, mixed> $payload
     */
    public function upsertPriceMatrix(array $payload): TowingPriceMatrix
    {
        if (empty($payload['service_class_id']) || empty($payload['service_type_id'])) {
            throw new InvalidArgumentException('Service class ID and type ID are required');
        }

        $existing = $this->findPriceMatrixByClassAndType(
            (int) $payload['service_class_id'],
            (int) $payload['service_type_id']
        );

        if ($existing) {
            return $this->updatePriceMatrix($existing->id, $payload);
        }

        return $this->createPriceMatrix($payload);
    }

    /**
     * Bulk update prices in the matrix
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, TowingPriceMatrix>
     */
    public function bulkUpsertPriceMatrix(array $entries): array
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $results = [];
            foreach ($entries as $entry) {
                $results[] = $this->upsertPriceMatrix($entry);
            }
            $pdo->commit();
            return $results;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function deletePriceMatrix(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM towing_price_matrix WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Calculate total price for a service
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function calculatePrice(int $classId, int $typeId, array $params = []): array
    {
        $matrix = $this->findPriceMatrixByClassAndType($classId, $typeId);
        if (!$matrix) {
            throw new InvalidArgumentException('No pricing found for this service class and type');
        }

        $loadedMiles = (float) ($params['loaded_miles'] ?? 0);
        $deadheadMiles = (float) ($params['deadhead_miles'] ?? 0);
        $isAfterHours = (bool) ($params['after_hours'] ?? false);
        $includeAccidentCleanup = (bool) ($params['accident_cleanup'] ?? false);
        $includeWinch = (bool) ($params['winch'] ?? false);
        $storageDays = (int) ($params['storage_days'] ?? 0);

        $multiplier = $isAfterHours ? $matrix->after_hours_multiplier : 1.0;

        $breakdown = [
            'rollout_fee' => $matrix->rollout_fee * $multiplier,
            'hookup_fee' => $matrix->hookup_fee * $multiplier,
            'onhook_fee' => $matrix->onhook_fee * $multiplier,
            'mileage_charge' => $loadedMiles * $matrix->mileage_rate * $multiplier,
            'deadhead_charge' => $deadheadMiles * $matrix->deadhead_rate * $multiplier,
            'accident_cleanup_fee' => $includeAccidentCleanup ? ($matrix->accident_cleanup_fee * $multiplier) : 0,
            'winch_fee' => $includeWinch ? ($matrix->winch_fee * $multiplier) : 0,
            'storage_fee' => $storageDays * $matrix->storage_daily_rate,
        ];

        $subtotal = array_sum($breakdown);
        $total = max($subtotal, $matrix->minimum_charge);

        return [
            'service_class' => $matrix->service_class_name,
            'service_type' => $matrix->service_type_name,
            'breakdown' => $breakdown,
            'subtotal' => round($subtotal, 2),
            'minimum_charge' => $matrix->minimum_charge,
            'total' => round($total, 2),
            'after_hours_applied' => $isAfterHours,
            'multiplier' => $multiplier,
        ];
    }

    private function hydratePriceMatrix(array $row): TowingPriceMatrix
    {
        $matrix = new TowingPriceMatrix($row);
        $matrix->is_active = (bool) $row['is_active'];
        $matrix->rollout_fee = (float) $row['rollout_fee'];
        $matrix->hookup_fee = (float) $row['hookup_fee'];
        $matrix->onhook_fee = (float) $row['onhook_fee'];
        $matrix->mileage_rate = (float) $row['mileage_rate'];
        $matrix->deadhead_rate = (float) $row['deadhead_rate'];
        $matrix->accident_cleanup_fee = (float) $row['accident_cleanup_fee'];
        $matrix->winch_fee = (float) $row['winch_fee'];
        $matrix->storage_daily_rate = (float) $row['storage_daily_rate'];
        $matrix->after_hours_multiplier = (float) $row['after_hours_multiplier'];
        $matrix->minimum_charge = (float) $row['minimum_charge'];
        $matrix->service_class_name = $row['service_class_name'] ?? null;
        $matrix->service_type_name = $row['service_type_name'] ?? null;
        $matrix->service_type_code = $row['service_type_code'] ?? null;
        return $matrix;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildPriceMatrixParams(array $payload, bool $isUpdate = false): array
    {
        if ($isUpdate) {
            return [
                'service_class_id' => $payload['service_class_id'] ?? null,
                'service_type_id' => $payload['service_type_id'] ?? null,
                'rollout_fee' => isset($payload['rollout_fee']) ? (float) $payload['rollout_fee'] : null,
                'hookup_fee' => isset($payload['hookup_fee']) ? (float) $payload['hookup_fee'] : null,
                'onhook_fee' => isset($payload['onhook_fee']) ? (float) $payload['onhook_fee'] : null,
                'mileage_rate' => isset($payload['mileage_rate']) ? (float) $payload['mileage_rate'] : null,
                'deadhead_rate' => isset($payload['deadhead_rate']) ? (float) $payload['deadhead_rate'] : null,
                'accident_cleanup_fee' => isset($payload['accident_cleanup_fee']) ? (float) $payload['accident_cleanup_fee'] : null,
                'winch_fee' => isset($payload['winch_fee']) ? (float) $payload['winch_fee'] : null,
                'storage_daily_rate' => isset($payload['storage_daily_rate']) ? (float) $payload['storage_daily_rate'] : null,
                'after_hours_multiplier' => isset($payload['after_hours_multiplier']) ? (float) $payload['after_hours_multiplier'] : null,
                'minimum_charge' => isset($payload['minimum_charge']) ? (float) $payload['minimum_charge'] : null,
                'is_active' => array_key_exists('is_active', $payload) ? (int) (bool) $payload['is_active'] : null,
                'notes' => $payload['notes'] ?? null,
            ];
        }

        return [
            'service_class_id' => (int) $payload['service_class_id'],
            'service_type_id' => (int) $payload['service_type_id'],
            'rollout_fee' => (float) ($payload['rollout_fee'] ?? 0),
            'hookup_fee' => (float) ($payload['hookup_fee'] ?? 0),
            'onhook_fee' => (float) ($payload['onhook_fee'] ?? 0),
            'mileage_rate' => (float) ($payload['mileage_rate'] ?? 0),
            'deadhead_rate' => (float) ($payload['deadhead_rate'] ?? 0),
            'accident_cleanup_fee' => (float) ($payload['accident_cleanup_fee'] ?? 0),
            'winch_fee' => (float) ($payload['winch_fee'] ?? 0),
            'storage_daily_rate' => (float) ($payload['storage_daily_rate'] ?? 0),
            'after_hours_multiplier' => (float) ($payload['after_hours_multiplier'] ?? 1.0),
            'minimum_charge' => (float) ($payload['minimum_charge'] ?? 0),
            'is_active' => isset($payload['is_active']) ? (int) (bool) $payload['is_active'] : 1,
            'notes' => $payload['notes'] ?? null,
        ];
    }
}
