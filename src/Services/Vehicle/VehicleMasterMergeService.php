<?php

namespace App\Services\Vehicle;

use App\Database\Connection;
use App\Models\VehicleMaster;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;

class VehicleMasterMergeService
{
    private VehicleMasterRepository $repository;
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(VehicleMasterRepository $repository, Connection $connection, ?AuditLogger $audit = null)
    {
        $this->repository = $repository;
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * Merge duplicate vehicle master rows into a target record, reassigning relationships and preserving the best metadata.
     *
     * @param array<int, int> $duplicateIds
     * @param array<string, mixed> $overrides
     */
    public function merge(int $targetId, array $duplicateIds, array $overrides = [], ?int $actorId = null): ?VehicleMaster
    {
        $target = $this->repository->find($targetId);
        if ($target === null) {
            throw new InvalidArgumentException('Target vehicle master record not found.');
        }

        if (in_array($targetId, $duplicateIds, true)) {
            throw new InvalidArgumentException('Target record cannot be merged into itself.');
        }

        $vehicles = [];
        foreach ($duplicateIds as $id) {
            $vehicle = $this->repository->find($id);
            if ($vehicle === null) {
                continue;
            }
            $vehicles[] = $vehicle;
        }

        if ($vehicles === []) {
            return $target;
        }

        $mergedPayload = $this->resolvePayload($target->toArray(), $vehicles, $overrides);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $updatedTarget = $this->repository->update($targetId, $mergedPayload);
            $this->reassignCustomerVehicles($targetId, $duplicateIds);
            $this->deleteDuplicates($duplicateIds);
            $pdo->commit();

            $this->logMerge($actorId, $targetId, $duplicateIds, $mergedPayload);

            return $updatedTarget;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<int, \App\Models\VehicleMaster> $vehicles
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function resolvePayload(array $basePayload, array $vehicles, array $overrides): array
    {
        $fields = ['year', 'make', 'model', 'engine', 'transmission', 'drive', 'trim'];
        foreach ($vehicles as $vehicle) {
            foreach ($fields as $field) {
                if ($basePayload[$field] === '' || $basePayload[$field] === null) {
                    $basePayload[$field] = $vehicle->{$field};
                }
            }
        }

        foreach ($overrides as $field => $value) {
            if (!in_array($field, $fields, true)) {
                continue;
            }
            $basePayload[$field] = $value;
        }

        return $basePayload;
    }

    private function reassignCustomerVehicles(int $targetId, array $duplicateIds): void
    {
        if ($duplicateIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE customer_vehicles SET vehicle_master_id = ? WHERE vehicle_master_id IN (' . $placeholders . ')'
        );

        $params = array_merge([$targetId], $duplicateIds);
        $stmt->execute($params);
    }

    private function deleteDuplicates(array $duplicateIds): void
    {
        if ($duplicateIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
        $stmt = $this->connection->pdo()->prepare('DELETE FROM vehicle_master WHERE id IN (' . $placeholders . ')');
        $stmt->execute($duplicateIds);
    }

    private function logMerge(?int $actorId, int $targetId, array $duplicateIds, array $payload): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry('vehicle_master.merged', 'vehicle_master', $targetId, $actorId, [
            'merged_ids' => array_values($duplicateIds),
            'attributes' => $payload,
        ]));
    }
}
