<?php

namespace App\Services\Estimate;

use App\Database\Connection;
use App\Models\Bundle;
use App\Models\BundleItem;
use InvalidArgumentException;
use PDO;
use Throwable;

class BundleService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): Bundle
    {
        $this->assertValid($payload);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO bundles (name, description, service_type_id, default_job_title, created_at, updated_at) VALUES (:name, :description, :service_type_id, :job_title, NOW(), NOW())'
            );
            $stmt->execute([
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'service_type_id' => $payload['service_type_id'] ?? null,
                'job_title' => $payload['default_job_title'],
            ]);

            $bundleId = (int) $pdo->lastInsertId();
            $this->persistItems($bundleId, $payload['items'] ?? []);
            $pdo->commit();

            return $this->find($bundleId) ?? new Bundle(['id' => $bundleId]);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $bundleId, array $payload): ?Bundle
    {
        $existing = $this->find($bundleId);
        if ($existing === null) {
            return null;
        }

        $this->assertValid($payload, true);
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE bundles SET name = COALESCE(:name, name), description = COALESCE(:description, description), service_type_id = COALESCE(:service_type_id, service_type_id), default_job_title = COALESCE(:job_title, default_job_title), updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                'id' => $bundleId,
                'name' => $payload['name'] ?? null,
                'description' => $payload['description'] ?? null,
                'service_type_id' => $payload['service_type_id'] ?? null,
                'job_title' => $payload['default_job_title'] ?? null,
            ]);

            if (array_key_exists('items', $payload)) {
                $pdo->prepare('DELETE FROM bundle_items WHERE bundle_id = :bundle_id')->execute(['bundle_id' => $bundleId]);
                $this->persistItems($bundleId, $payload['items']);
            }

            $pdo->commit();
            return $this->find($bundleId);
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function find(int $bundleId): ?Bundle
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM bundles WHERE id = :id');
        $stmt->execute(['id' => $bundleId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new Bundle($row);
    }

    /**
     * @return array<int, Bundle>
     */
    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT * FROM bundles ORDER BY name ASC');
        return array_map(static fn (array $row) => new Bundle($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{title:string,service_type_id?:int,items:array<int, array<string, mixed>>}
     */
    public function buildEstimateJobFromBundle(int $bundleId): array
    {
        $bundle = $this->find($bundleId);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found.');
        }

        $items = $this->fetchItems($bundleId);

        return [
            'title' => $bundle->default_job_title,
            'service_type_id' => $bundle->service_type_id,
            'items' => array_map(static function (BundleItem $item): array {
                return [
                    'type' => $item->type,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'taxable' => $item->taxable,
                ];
            }, $items),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function persistItems(int $bundleId, array $items): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO bundle_items (bundle_id, type, description, quantity, unit_price, taxable) VALUES (:bundle_id, :type, :description, :quantity, :unit_price, :taxable)'
        );

        foreach ($items as $item) {
            if (empty($item['description']) || !isset($item['type'])) {
                throw new InvalidArgumentException('Bundle items require type and description.');
            }

            $stmt->execute([
                'bundle_id' => $bundleId,
                'type' => $item['type'],
                'description' => $item['description'],
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'taxable' => isset($item['taxable']) ? (int) (bool) $item['taxable'] : 1,
            ]);
        }
    }

    /**
     * @return array<int, BundleItem>
     */
    private function fetchItems(int $bundleId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM bundle_items WHERE bundle_id = :bundle_id ORDER BY id ASC');
        $stmt->execute(['bundle_id' => $bundleId]);

        return array_map(static fn (array $row) => new BundleItem($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertValid(array $payload, bool $isUpdate = false): void
    {
        if (!$isUpdate) {
            foreach (['name', 'default_job_title'] as $field) {
                if (empty($payload[$field])) {
                    throw new InvalidArgumentException('Bundle missing required field: ' . $field);
                }
            }
        }

        if (isset($payload['items']) && !is_array($payload['items'])) {
            throw new InvalidArgumentException('Bundle items must be an array.');
        }
    }
}
