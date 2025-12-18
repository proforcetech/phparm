<?php

namespace App\CMS\Controllers;

use App\CMS\Models\Category;
use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use PDO;

class CategoryController
{
    private Connection $connection;
    private AccessGate $gate;

    public function __construct(Connection $connection, AccessGate $gate)
    {
        $this->connection = $connection;
        $this->gate = $gate;
    }

    /**
     * Get all categories with optional filtering
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'cms.categories.view');

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :search OR slug LIKE :search OR description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 100;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $sql = 'SELECT * FROM cms_categories';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY sort_order ASC, name ASC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->mapCategory($row)->toArray(), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get category by ID
     *
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.categories.view');

        $category = $this->find($id);

        return $category?->toArray();
    }

    /**
     * Create a new category
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->gate->assert($user, 'cms.categories.create');

        $payload = $this->preparePayload($data, true);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cms_categories (name, slug, description, status, sort_order, meta_title, meta_description, meta_keywords, created_at, updated_at) '
            . 'VALUES (:name, :slug, :description, :status, :sort_order, :meta_title, :meta_description, :meta_keywords, NOW(), NOW())'
        );

        $stmt->execute($payload);

        $category = $this->find((int) $this->connection->pdo()->lastInsertId())?->toArray() ?? [];

        return $category;
    }

    /**
     * Update an existing category
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->gate->assert($user, 'cms.categories.update');

        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $payload = $this->preparePayload($data, false, $existing);
        $payload['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_categories SET name = :name, slug = :slug, description = :description, status = :status, '
            . 'sort_order = :sort_order, meta_title = :meta_title, meta_description = :meta_description, '
            . 'meta_keywords = :meta_keywords, updated_at = NOW() '
            . 'WHERE id = :id'
        );

        $stmt->execute($payload);

        return $this->find($id)?->toArray();
    }

    /**
     * Delete a category
     * Pages in this category will have their category_id set to NULL
     */
    public function destroy(User $user, int $id): bool
    {
        $this->gate->assert($user, 'cms.categories.delete');

        $stmt = $this->connection->pdo()->prepare('DELETE FROM cms_categories WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    /**
     * Get count of pages in a category
     */
    public function getPageCount(int $categoryId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM cms_pages WHERE category_id = :category_id'
        );
        $stmt->execute(['category_id' => $categoryId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Get category by slug (public method)
     *
     * @return array<string, mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_categories WHERE slug = :slug AND status = "published" LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapCategory($row)->toArray();
    }

    private function find(int $id): ?Category
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM cms_categories WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapCategory($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapCategory(array $row): Category
    {
        return new Category([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => $row['description'] ?? null,
            'status' => (string) $row['status'],
            'sort_order' => (int) ($row['sort_order'] ?? 0),
            'meta_title' => $row['meta_title'] ?? null,
            'meta_description' => $row['meta_description'] ?? null,
            'meta_keywords' => $row['meta_keywords'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, bool $isCreate = true, ?Category $existing = null): array
    {
        $name = $data['name'] ?? $existing?->name ?? 'Untitled Category';
        $slugSource = $data['slug'] ?? $name;
        $status = $data['status'] ?? $existing?->status ?? 'published';

        return [
            'name' => (string) $name,
            'slug' => $this->slugify((string) $slugSource),
            'description' => $data['description'] ?? $existing?->description,
            'status' => (string) $status,
            'sort_order' => isset($data['sort_order']) ? (int) $data['sort_order'] : ($existing?->sort_order ?? 0),
            'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
            'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
            'meta_keywords' => $data['meta_keywords'] ?? $existing?->meta_keywords,
        ];
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value ?: uniqid('category-'), '-');
    }
}
