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
    private const ALLOWED_ITEM_TYPES = ['LABOR', 'PART', 'FEE', 'DISCOUNT'];

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
                'INSERT INTO bundles (name, description, internal_notes, discount_type, discount_value, service_type_id, default_job_title, is_active, sort_order, created_at, updated_at) VALUES (:name, :description, :internal_notes, :discount_type, :discount_value, :service_type_id, :job_title, :is_active, :sort_order, NOW(), NOW())'
            );
            $stmt->execute([
                'name' => $payload['name'],
                'description' => $payload['description'] ?? null,
                'internal_notes' => $payload['internal_notes'] ?? null,
                'discount_type' => $payload['discount_type'] ?? null,
                'discount_value' => $payload['discount_value'] ?? null,
                'service_type_id' => $payload['service_type_id'] ?? null,
                'job_title' => $payload['default_job_title'],
                'is_active' => isset($payload['is_active']) ? (int) (bool) $payload['is_active'] : 1,
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
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
                'UPDATE bundles SET name = COALESCE(:name, name), description = COALESCE(:description, description), internal_notes = COALESCE(:internal_notes, internal_notes), discount_type = COALESCE(:discount_type, discount_type), discount_value = COALESCE(:discount_value, discount_value), service_type_id = COALESCE(:service_type_id, service_type_id), default_job_title = COALESCE(:job_title, default_job_title), is_active = COALESCE(:is_active, is_active), sort_order = COALESCE(:sort_order, sort_order), updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                'id' => $bundleId,
                'name' => $payload['name'] ?? null,
                'description' => $payload['description'] ?? null,
                'internal_notes' => $payload['internal_notes'] ?? null,
                'discount_type' => $payload['discount_type'] ?? null,
                'discount_value' => $payload['discount_value'] ?? null,
                'service_type_id' => $payload['service_type_id'] ?? null,
                'job_title' => $payload['default_job_title'] ?? null,
                'is_active' => array_key_exists('is_active', $payload) ? (int) (bool) $payload['is_active'] : null,
                'sort_order' => $payload['sort_order'] ?? null,
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

        if ($row === false) {
            return null;
        }

        $bundle = new Bundle($row);
        $bundle->is_active = (bool) $row['is_active'];
        $bundle->sort_order = (int) $row['sort_order'];

        return $bundle;
    }

    /**
     * @return array<int, Bundle>
     */
    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT * FROM bundles ORDER BY sort_order ASC, name ASC');
        return array_map(function (array $row): Bundle {
            $bundle = new Bundle($row);
            $bundle->is_active = (bool) $row['is_active'];
            $bundle->sort_order = (int) $row['sort_order'];

            return $bundle;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['query'])) {
            $conditions[] = '(name LIKE :query OR description LIKE :query)';
            $params['query'] = '%' . $filters['query'] . '%';
        }

        if (isset($filters['active'])) {
            $conditions[] = 'is_active = :active';
            $params['active'] = (int) (bool) $filters['active'];
        }

        $where = count($conditions) > 0 ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT b.*, COUNT(i.id) AS item_count, st.name AS service_type_name FROM bundles b LEFT JOIN bundle_items i ON b.id = i.bundle_id LEFT JOIN service_types st ON b.service_type_id = st.id {$where} GROUP BY b.id ORDER BY b.sort_order ASC, b.name ASC LIMIT :limit OFFSET :offset";
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = $key === 'query' ? PDO::PARAM_STR : PDO::PARAM_INT;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(function (array $row): array {
            $bundle = new Bundle($row);
            $bundle->is_active = (bool) $row['is_active'];
            $bundle->sort_order = (int) $row['sort_order'];
            $bundle->item_count = isset($row['item_count']) ? (int) $row['item_count'] : null;

            $data = $bundle->toArray();
            $data['service_type_name'] = $row['service_type_name'] ?? null;

            return $data;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(int $bundleId): ?array
    {
        $bundle = $this->find($bundleId);
        if ($bundle === null) {
            return null;
        }

        $items = array_map(static fn (BundleItem $item) => $item->toArray(), $this->fetchItems($bundleId));

        $data = $bundle->toArray();
        $data['service_type_name'] = $this->lookupServiceTypeName($bundle->service_type_id);

        return array_merge($data, ['items' => $items]);
    }

    public function delete(int $bundleId): bool
    {
        $this->connection->pdo()->prepare('DELETE FROM bundle_items WHERE bundle_id = :id')->execute(['id' => $bundleId]);
        $stmt = $this->connection->pdo()->prepare('DELETE FROM bundles WHERE id = :id');
        $stmt->execute(['id' => $bundleId]);

        return $stmt->rowCount() > 0;
    }

    private function lookupServiceTypeName(?int $serviceTypeId): ?string
    {
        if ($serviceTypeId === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT name FROM service_types WHERE id = :id');
        $stmt->execute(['id' => $serviceTypeId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row['name'] ?? null;
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
     * @return array<int, array<string, mixed>>
     */
    public function fetchBundleItems(int $bundleId): array
    {
        return array_map(static function (BundleItem $item): array {
            return [
                'type' => $item->type,
                'description' => $item->description,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'taxable' => $item->taxable,
                'sort_order' => $item->sort_order,
            ];
        }, $this->fetchItems($bundleId));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private function persistItems(int $bundleId, array $items): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO bundle_items (bundle_id, type, description, quantity, unit_price, list_price, taxable, sort_order) VALUES (:bundle_id, :type, :description, :quantity, :unit_price, :list_price, :taxable, :sort_order)'
        );

        foreach ($items as $index => $item) {
            if (empty($item['description']) || !isset($item['type'])) {
                throw new InvalidArgumentException('Bundle items require type and description.');
            }

            $type = strtoupper((string) $item['type']);
            if (!in_array($type, self::ALLOWED_ITEM_TYPES, true)) {
                throw new InvalidArgumentException('Invalid bundle item type: ' . $type);
            }

            $stmt->execute([
                'bundle_id' => $bundleId,
                'type' => $type,
                'description' => $item['description'],
                'quantity' => (float) ($item['quantity'] ?? 1),
                'unit_price' => (float) ($item['unit_price'] ?? 0),
                'list_price' => (float) ($item['list_price'] ?? 0),
                'taxable' => isset($item['taxable']) ? (int) (bool) $item['taxable'] : 1,
                'sort_order' => isset($item['sort_order']) ? (int) $item['sort_order'] : $index,
            ]);
        }
    }

    /**
     * @return array<int, BundleItem>
     */
    private function fetchItems(int $bundleId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM bundle_items WHERE bundle_id = :bundle_id ORDER BY sort_order ASC, id ASC');
        $stmt->execute(['bundle_id' => $bundleId]);

        return array_map(static function (array $row): BundleItem {
            $item = new BundleItem($row);
            $item->taxable = (bool) $row['taxable'];
            $item->sort_order = (int) $row['sort_order'];

            return $item;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
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

        if (isset($payload['items']) && is_array($payload['items'])) {
            foreach ($payload['items'] as $item) {
                if (!isset($item['type'])) {
                    continue;
                }
                $type = strtoupper((string) $item['type']);
                if (!in_array($type, self::ALLOWED_ITEM_TYPES, true)) {
                    throw new InvalidArgumentException('Invalid bundle item type: ' . $type);
                }
            }
        }

        if (isset($payload['discount_type'])) {
            $discountType = (string) $payload['discount_type'];
            if (!in_array($discountType, ['fixed', 'percent'], true)) {
                throw new InvalidArgumentException('Invalid bundle discount type: ' . $discountType);
            }
        }
    }
}
