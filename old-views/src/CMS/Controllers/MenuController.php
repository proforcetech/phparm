<?php

namespace App\CMS\Controllers;

use App\CMS\Models\Menu;
use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Services\CMS\CMSCacheService;
use DateTimeImmutable;
use PDO;

class MenuController
{
    private Connection $connection;
    private AccessGate $gate;
    private ?CMSCacheService $cache;

    public function __construct(Connection $connection, AccessGate $gate, ?CMSCacheService $cache = null)
    {
        $this->connection = $connection;
        $this->gate = $gate;
        $this->cache = $cache;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'cms.menus.view');

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        $sql = 'SELECT * FROM cms_menus';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row) => $this->mapMenu($row)->toArray(), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.menus.view');

        $menu = $this->find($id);

        return $menu?->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->gate->assert($user, 'cms.menus.create');

        $payload = $this->preparePayload($data, true);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cms_menus (name, slug, status, description, items, meta_title, meta_description, published_at, created_at, updated_at) '
            . 'VALUES (:name, :slug, :status, :description, :items, :meta_title, :meta_description, :published_at, NOW(), NOW())'
        );

        $stmt->execute($payload);

        $menu = $this->find((int) $this->connection->pdo()->lastInsertId())?->toArray() ?? [];

        $this->invalidateCache($menu['slug'] ?? '');

        return $menu;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->gate->assert($user, 'cms.menus.update');

        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $existingSlug = $existing->slug;
        $payload = $this->preparePayload($data, false, $existing);
        $payload['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_menus SET name = :name, slug = :slug, status = :status, description = :description, items = :items, '
            . 'meta_title = :meta_title, meta_description = :meta_description, published_at = :published_at, updated_at = NOW() '
            . 'WHERE id = :id'
        );

        $stmt->execute($payload);

        $this->invalidateCache($payload['slug']);
        if ($payload['slug'] !== $existingSlug) {
            $this->invalidateCache($existingSlug);
        }

        return $this->find($id)?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->gate->assert($user, 'cms.menus.delete');

        $menu = $this->find($id)?->toArray();

        $stmt = $this->connection->pdo()->prepare('DELETE FROM cms_menus WHERE id = :id');

        $deleted = $stmt->execute(['id' => $id]);

        if ($deleted && $menu !== null) {
            $this->invalidateCache($menu['slug'] ?? '');
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publishedMenu(string $slug): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_menus WHERE slug = :slug AND status = "published" ORDER BY published_at DESC LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapMenu($row)->toArray();
    }

    private function find(int $id): ?Menu
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM cms_menus WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapMenu($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapMenu(array $row): Menu
    {
        return new Menu([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'status' => (string) $row['status'],
            'description' => $row['description'] ?? null,
            'items' => $row['items'] ?? null,
            'meta_title' => $row['meta_title'] ?? null,
            'meta_description' => $row['meta_description'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, bool $isCreate = true, ?Menu $existing = null): array
    {
        $name = $data['name'] ?? $existing?->name ?? 'Menu';
        $slugSource = $data['slug'] ?? $name;
        $status = $data['status'] ?? $existing?->status ?? 'draft';
        $publishedAt = $data['published_at'] ?? $existing?->published_at ?? null;

        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        $items = $data['items'] ?? $existing?->items;
        if (is_array($items)) {
            $items = json_encode($items, JSON_THROW_ON_ERROR);
        }

        return [
            'name' => (string) $name,
            'slug' => $this->slugify((string) $slugSource),
            'status' => (string) $status,
            'description' => $data['description'] ?? $existing?->description,
            'items' => $items,
            'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
            'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
            'published_at' => $publishedAt,
        ];
    }

    private function invalidateCache(string $slug): void
    {
        if ($slug === '') {
            return;
        }

        $this->cache?->forgetPrefix('menu:' . $this->slugify($slug));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value ?: uniqid('menu-'), '-');
    }
}
