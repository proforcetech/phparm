<?php

namespace App\Services\SoftwareInventory;

use App\Database\Connection;
use App\Models\SoftwareAsset;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `software_assets` — Phase 14 / M9 of
 * docs/woms-expansion-plan.md.
 */
class SoftwareAssetRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int|null, publisher?: string, title?: string,
     *              category?: string, platform?: string, is_active?: int,
     *              search?: string, include_shared?: bool} $filters
     * @return array<int, SoftwareAsset>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM software_assets ' . $where
            . ' ORDER BY publisher ASC, title ASC, version ASC, id DESC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => SoftwareAsset::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM software_assets ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?SoftwareAsset
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM software_assets WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? SoftwareAsset::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): SoftwareAsset
    {
        $publisher = trim((string) ($data['publisher'] ?? ''));
        $title = trim((string) ($data['title'] ?? ''));
        if ($publisher === '' || $title === '') {
            throw new InvalidArgumentException('publisher and title are required');
        }
        if (array_key_exists('category', $data) && $data['category'] !== null) {
            $this->validateCategory((string) $data['category']);
        }
        if (array_key_exists('platform', $data) && $data['platform'] !== null) {
            $this->validatePlatform((string) $data['platform']);
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO software_assets
                (customer_id, publisher, title, version, edition, category,
                 platform, sku, description, is_active)
             VALUES
                (:customer_id, :publisher, :title, :version, :edition, :category,
                 :platform, :sku, :description, :is_active)'
        );
        $stmt->execute([
            'customer_id' => $this->nullableInt($data['customer_id'] ?? null),
            'publisher' => $publisher,
            'title' => $title,
            'version' => $this->nullableString($data['version'] ?? null),
            'edition' => $this->nullableString($data['edition'] ?? null),
            'category' => $this->nullableString($data['category'] ?? null),
            'platform' => $this->nullableString($data['platform'] ?? null),
            'sku' => $this->nullableString($data['sku'] ?? null),
            'description' => $this->nullableString($data['description'] ?? null),
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created software_asset');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): SoftwareAsset
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("software_asset {$id} not found");
        }
        if (array_key_exists('category', $data) && $data['category'] !== null) {
            $this->validateCategory((string) $data['category']);
        }
        if (array_key_exists('platform', $data) && $data['platform'] !== null) {
            $this->validatePlatform((string) $data['platform']);
        }

        $fields = [];
        $params = ['id' => $id];

        $writable = ['publisher', 'title', 'version', 'edition', 'category',
                     'platform', 'sku', 'description'];
        foreach ($writable as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        if (array_key_exists('customer_id', $data)) {
            $fields[] = 'customer_id = :customer_id';
            $params['customer_id'] = $this->nullableInt($data['customer_id']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE software_assets SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("software_asset {$id} not found after update");
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM software_assets WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    private function validateCategory(string $value): void
    {
        if (!in_array($value, SoftwareAsset::ALLOWED_CATEGORIES, true)) {
            throw new InvalidArgumentException(
                'Invalid category. Allowed: ' . implode(', ', SoftwareAsset::ALLOWED_CATEGORIES)
            );
        }
    }

    private function validatePlatform(string $value): void
    {
        if (!in_array($value, SoftwareAsset::ALLOWED_PLATFORMS, true)) {
            throw new InvalidArgumentException(
                'Invalid platform. Allowed: ' . implode(', ', SoftwareAsset::ALLOWED_PLATFORMS)
            );
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (array_key_exists('customer_id', $filters)) {
            if ($filters['customer_id'] === null) {
                $where .= ' AND customer_id IS NULL';
            } else {
                if (!empty($filters['include_shared'])) {
                    $where .= ' AND (customer_id = :customer_id OR customer_id IS NULL)';
                } else {
                    $where .= ' AND customer_id = :customer_id';
                }
                $params['customer_id'] = (int) $filters['customer_id'];
            }
        }
        if (!empty($filters['publisher'])) {
            $where .= ' AND publisher = :publisher';
            $params['publisher'] = (string) $filters['publisher'];
        }
        if (!empty($filters['title'])) {
            $where .= ' AND title = :title';
            $params['title'] = (string) $filters['title'];
        }
        if (!empty($filters['category'])) {
            $where .= ' AND category = :category';
            $params['category'] = (string) $filters['category'];
        }
        if (!empty($filters['platform'])) {
            $where .= ' AND platform = :platform';
            $params['platform'] = (string) $filters['platform'];
        }
        if (array_key_exists('is_active', $filters)) {
            $where .= ' AND is_active = :is_active';
            $params['is_active'] = (int) (bool) $filters['is_active'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (publisher LIKE :search OR title LIKE :search OR sku LIKE :search)';
            $params['search'] = '%' . (string) $filters['search'] . '%';
        }

        return [$where, $params];
    }
}
