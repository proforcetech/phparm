<?php

namespace App\CMS\Controllers;

use App\CMS\Models\Media;
use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Services\CMS\CMSCacheService;
use DateTimeImmutable;
use PDO;

class MediaController
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
        $this->gate->assert($user, 'cms.media.view');

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(file_name LIKE :search OR slug LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql = 'SELECT * FROM cms_media';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row) => $this->mapMedia($row)->toArray(), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.media.view');

        $media = $this->find($id);

        return $media?->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->gate->assert($user, 'cms.media.create');

        $payload = $this->preparePayload($data, true);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cms_media (file_name, slug, url, mime_type, size_bytes, title, alt_text, status, published_at, created_at, updated_at) '
            . 'VALUES (:file_name, :slug, :url, :mime_type, :size_bytes, :title, :alt_text, :status, :published_at, NOW(), NOW())'
        );
        $stmt->execute($payload);

        $media = $this->find((int) $this->connection->pdo()->lastInsertId())?->toArray() ?? [];

        $this->invalidateCache($media['slug'] ?? '');

        return $media;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->gate->assert($user, 'cms.media.update');

        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $existingSlug = $existing->slug;
        $payload = $this->preparePayload($data, false, $existing);
        $payload['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_media SET file_name = :file_name, slug = :slug, url = :url, mime_type = :mime_type, size_bytes = :size_bytes, '
            . 'title = :title, alt_text = :alt_text, status = :status, published_at = :published_at, updated_at = NOW() WHERE id = :id'
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
        $this->gate->assert($user, 'cms.media.delete');

        $media = $this->find($id)?->toArray();

        $stmt = $this->connection->pdo()->prepare('DELETE FROM cms_media WHERE id = :id');

        $deleted = $stmt->execute(['id' => $id]);

        if ($deleted && $media !== null) {
            $this->invalidateCache($media['slug'] ?? '');
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publishedMedia(string $slug): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_media WHERE slug = :slug AND status = "published" ORDER BY published_at DESC LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapMedia($row)->toArray();
    }

    private function find(int $id): ?Media
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM cms_media WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapMedia($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapMedia(array $row): Media
    {
        return new Media([
            'id' => (int) $row['id'],
            'file_name' => (string) $row['file_name'],
            'slug' => (string) $row['slug'],
            'url' => (string) $row['url'],
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
            'title' => $row['title'] ?? null,
            'alt_text' => $row['alt_text'] ?? null,
            'status' => (string) $row['status'],
            'published_at' => $row['published_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, bool $isCreate = true, ?Media $existing = null): array
    {
        $fileName = $data['file_name'] ?? $existing?->file_name ?? 'media';
        $slugSource = $data['slug'] ?? $fileName;
        $status = $data['status'] ?? $existing?->status ?? 'published';
        $publishedAt = $data['published_at'] ?? $existing?->published_at ?? null;

        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        return [
            'file_name' => (string) $fileName,
            'slug' => $this->slugify((string) $slugSource),
            'url' => (string) ($data['url'] ?? $existing?->url ?? ''),
            'mime_type' => $data['mime_type'] ?? $existing?->mime_type,
            'size_bytes' => isset($data['size_bytes']) ? (int) $data['size_bytes'] : $existing?->size_bytes,
            'title' => $data['title'] ?? $existing?->title,
            'alt_text' => $data['alt_text'] ?? $existing?->alt_text,
            'status' => (string) $status,
            'published_at' => $publishedAt,
        ];
    }

    private function invalidateCache(string $slug): void
    {
        if ($slug === '') {
            return;
        }

        $this->cache?->forgetPrefix('media:' . $this->slugify($slug));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value ?: uniqid('media-'), '-');
    }
}
